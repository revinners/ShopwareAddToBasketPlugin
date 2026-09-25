<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Revinners\AddToBasketPlugin\DTO\AddToBasketRequest;
use Revinners\AddToBasketPlugin\Service\AddToBasketRequestValidator;
use Revinners\AddToBasketPlugin\Service\CartManager;
use Revinners\AddToBasketPlugin\Service\GiftCardAmountRejectedException;
use Revinners\AddToBasketPlugin\Service\ProductFinder;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class AddToBasketController extends StorefrontController
{
    public function __construct(
        private readonly AddToBasketRequestValidator $validator,
        private readonly ProductFinder               $productFinder,
        private readonly CartManager                 $cartManager,
        private readonly LoggerInterface             $logger,
    )
    {
    }

    #[Route('/add-to-basket', name: 'frontend.add_to_basket', defaults: ['XmlHttpRequest' => 'true'], methods: ['GET'])]
    public function addToBasket(Request $request, Cart $cart, Context $context, SalesChannelContext $channelContext): Response
    {
        $qty = (int)$request->query->get('qty');
        $dto = new AddToBasketRequest(
            $request->query->get('sku'),
            $qty,
            // A decimal comma survives the trip from a text input; (float)'51,50' would
            // silently truncate to 51, so normalise before the cast.
            (float)str_replace(',', '.', (string)$request->query->get('amount', '0')),
            $request->get('message', ''),
            $request->query->get('originalSku')
        );

        $errors = $this->validator->validate($dto);
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $product = $this->productFinder->findBySku($dto->getSku(), $context);
        if (!$product) {
            return new JsonResponse([
                'success' => false,
                'message' => sprintf('Product with SKU %s not found', $dto->getSku()),
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $added = $this->cartManager->addToCart($cart, $product, $dto, $channelContext);
        } catch (GiftCardAmountRejectedException) {
            return $this->giftCardRejectedResponse();
        }

        $lineItem = $this->findAddedLineItem($cart, $added, $product->getId());

        if (!$lineItem) {
            return $this->lineItemMissingResponse($cart, $dto);
        }

        $finalPrice = $this->grossPrice($lineItem, $qty, $channelContext);

        return new JsonResponse([
            'price' => $finalPrice,
            'qty' => $qty,
            'success' => true,
            'message' => 'Product added to the basket',
        ]);
    }

    #[Route('/add-multiple-to-basket', name: 'frontend.add_multiple_to_basket', defaults: ['XmlHttpRequest' => 'true'], methods: ['POST'])]
    public function addMultipleToBasket(Request $request, Cart $cart, Context $context, SalesChannelContext $channelContext): Response
    {
        $items = $request->request->all('items');
        if (empty($items)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak produktów do dodania',
            ], Response::HTTP_BAD_REQUEST);
        }

        $results = [];
        foreach ($items as $item) {
            $qty = (int)($item['qty']);
            $dto = new AddToBasketRequest(
                $item['sku'],
                $qty,
                (float)($item['amount'] ?? 0.0),
                $item['message'] ?? null
            );

            $errors = $this->validator->validate($dto);
            if (!empty($errors)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $errors,
                ], Response::HTTP_BAD_REQUEST);
            }

            $product = $this->productFinder->findBySku($dto->getSku(), $context);
            if (!$product) {
                return new JsonResponse([
                    'success' => false,
                    'message' => sprintf('Product with SKU %s not found', $dto->getSku()),
                ], Response::HTTP_NOT_FOUND);
            }

            try {
                $added = $this->cartManager->addToCart($cart, $product, $dto, $channelContext);
            } catch (GiftCardAmountRejectedException) {
                return $this->giftCardRejectedResponse();
            }

            $lineItem = $this->findAddedLineItem($cart, $added, $product->getId());

            if (!$lineItem) {
                return $this->lineItemMissingResponse($cart, $dto);
            }

            $finalPrice = $this->grossPrice($lineItem, $qty, $channelContext);

            $results[] = [
                'sku' => $dto->getSku(),
                'price' => $finalPrice,
                'qty' => $qty,
                'success' => true,
                'message' => 'Product added to the basket',
            ];
        }

        return new JsonResponse([
            'results' => $results,
        ]);
    }

    /**
     * The position this request added. CartManager hands it back; the lookup by product id is only
     * a fallback, because it picks the first of several gift cards rather than the new one.
     */
    private function findAddedLineItem(Cart $cart, ?LineItem $added, string $productId): ?LineItem
    {
        if ($added !== null) {
            // Read back from the cart: a processor may have thrown the position out again.
            return $cart->getLineItems()->get($added->getId());
        }

        return $cart->getLineItems()->firstWhere(fn($item) => $item->getReferencedId() === $productId && in_array($item->getType(), ['product', 'revinners_bundle'], true));
    }

    /**
     * Gross price of the quantity just added.
     *
     * The unit price is gross in a gross (B2C) context and net in a net (B2B) one. The tax rate
     * used to be multiplied on top in both, so every B2C response came out one VAT rate too high
     * (a 1000 zł gift card answered 1230.00). Only a net context needs the tax added, and it is
     * taken from the calculated taxes so a line with several tax rules is still right. A tax-free
     * context has no tax to add.
     */
    private function grossPrice(LineItem $lineItem, int $qty, SalesChannelContext $channelContext): string
    {
        $price = $lineItem->getPrice();

        if ($price === null) {
            return number_format(0, 2, '.', '');
        }

        $unitPrice = $price->getUnitPrice();

        if ($channelContext->getTaxState() === CartPrice::TAX_STATE_NET) {
            $unitPrice += $price->getCalculatedTaxes()->getAmount() / max(1, $price->getQuantity());
        }

        return number_format($unitPrice * $qty, 2, '.', '');
    }

    /**
     * A gift card with no amount, or one outside the product's range, is not added at all. The
     * storefront keys on `errorCode` to show the message next to the amount field instead of the
     * "added to cart" modal.
     */
    private function giftCardRejectedResponse(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'errorCode' => 'giftCardAmountInvalid',
            'message' => $this->trans('revinnersAddToBasket.giftCardAmountInvalid'),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The product exists but the cart processors threw the line item back out
     * (inactive product, not visible in this sales channel, closeout with no
     * stock, ...). Shopware records the reason as a cart error, so surface it in
     * the log and in the response instead of a bare "could not retrieve".
     */
    private function lineItemMissingResponse(Cart $cart, AddToBasketRequest $dto): JsonResponse
    {
        $cartErrors = [];
        foreach ($cart->getErrors() as $error) {
            $cartErrors[] = [
                'key' => $error->getMessageKey(),
                'message' => $error->getMessage(),
            ];
        }

        $this->logger->error('Add to basket: could not retrieve line item from cart after adding it', [
            'sku' => $dto->getSku(),
            'originalSku' => $dto->getOriginalSku(),
            'qty' => $dto->getQuantity(),
            'cartErrors' => $cartErrors,
        ]);

        return new JsonResponse([
            'success' => false,
            'message' => 'Could not retrieve line item from cart',
            'errors' => $cartErrors,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
