<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Storefront\Controller;

use Revinners\AddToBasketPlugin\DTO\AddToBasketRequest;
use Revinners\AddToBasketPlugin\Service\AddToBasketRequestValidator;
use Revinners\AddToBasketPlugin\Service\CartManager;
use Revinners\AddToBasketPlugin\Service\ProductFinder;
use Shopware\Core\Checkout\Cart\Cart;
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
    )
    {
    }

    #[Route('/add-to-basket', name: 'frontend.add_to_basket', defaults: ['XmlHttpRequest' => 'true'], methods: ['GET'])]
    public function addToBasket(Request $request, Cart $cart, Context $context, SalesChannelContext $channelContext): Response
    {
        $dto = new AddToBasketRequest(
            $request->query->get('sku'),
            (int)$request->query->get('qty'),
            (float)$request->query->get('amount', 0.0),
            $request->get('message')
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

        $this->cartManager->addToCart($cart, $product, $dto, $channelContext);

        return new JsonResponse([
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
            $dto = new AddToBasketRequest(
                $item['sku'],
                (int)($item['qty']),
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

            $this->cartManager->addToCart($cart, $product, $dto, $channelContext);

            $results[] = [
                'sku' => $dto->getSku(),
                'success' => true,
                'message' => 'Product added to the basket',
            ];
        }

        return new JsonResponse([
            'results' => $results,
        ]);
    }
}
