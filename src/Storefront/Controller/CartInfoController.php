<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Storefront\Controller;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class CartInfoController extends StorefrontController
{
    private const EXCLUDED_LINE_ITEM_TYPES = [
        'battery_deposit',
        LineItem::PROMOTION_LINE_ITEM_TYPE,
    ];

    #[Route('/cart-info', name: 'frontend.cart_info', defaults: ['XmlHttpRequest' => 'true'], methods: ['GET'])]
    public function cartInfo(Cart $cart, SalesChannelContext $channelContext): Response
    {
        $lineItems = $cart->getLineItems();
        $taxStatus = $cart->getPrice()?->getTaxStatus() ?? CartPrice::TAX_STATE_GROSS;

        $quantity = 0;
        $grossPrice = 0.0;
        $netPrice = 0.0;
        $promotionItems = [];

        foreach ($lineItems as $lineItem) {
            $type = $lineItem->getType();

            // Collect promotions to report the applied discounts, but still keep
            // them out of the totals via the exclusion list below.
            if ($type === LineItem::PROMOTION_LINE_ITEM_TYPE) {
                $promotionItems[] = $lineItem;
            }

            if (in_array($type, self::EXCLUDED_LINE_ITEM_TYPES, true)) {
                continue;
            }

            $quantity += $lineItem->getQuantity();

            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }

            $lineTotal = $price->getTotalPrice();
            $taxAmount = $price->getCalculatedTaxes()->getAmount();

            if ($taxStatus === CartPrice::TAX_STATE_NET) {
                $netPrice += $lineTotal;
                $grossPrice += $lineTotal + $taxAmount;
            } elseif ($taxStatus === CartPrice::TAX_STATE_FREE) {
                $netPrice += $lineTotal;
                $grossPrice += $lineTotal;
            } else {
                $grossPrice += $lineTotal;
                $netPrice += $lineTotal - $taxAmount;
            }
        }

        return new JsonResponse([
            'success' => true,
            'count' => $quantity,
            'lineItemCount' => $lineItems->count(),
            'totalPrice' => number_format($grossPrice, 2, '.', ''),
            'netPrice' => number_format($netPrice, 2, '.', ''),
            'currencyId' => $channelContext->getCurrencyId(),
            'appliedDiscounts' => $this->buildAppliedDiscounts($promotionItems, $grossPrice),
        ]);
    }

    /**
     * Describes each applied promotion so the storefront can tell which discount
     * is really active (Shopware applies only the surviving promotions after
     * exclusions). `percentage` is the declared value for percentage discounts,
     * otherwise the effective share of the cart item value. `hasCode` separates
     * automatic campaign promotions from redeemed coupon codes.
     *
     * @param LineItem[] $promotionItems
     *
     * @return array<int, array{percentage: float, isPercentage: bool, hasCode: bool, promotionId: string|null}>
     */
    private function buildAppliedDiscounts(array $promotionItems, float $itemsGross): array
    {
        $discounts = [];

        foreach ($promotionItems as $promotion) {
            $payload = $promotion->getPayload();
            $isPercentage = ($payload['discountType'] ?? null) === 'percentage';

            if ($isPercentage) {
                $percentage = (float) ($payload['value'] ?? 0);
            } elseif (isset($payload['discountPercentage'])) {
                $percentage = (float) $payload['discountPercentage'];
            } else {
                $amount = $promotion->getPrice()?->getTotalPrice() ?? 0.0;
                $percentage = $itemsGross > 0 ? abs($amount) / $itemsGross * 100 : 0.0;
            }

            $code = $payload['code'] ?? null;

            $discounts[] = [
                'percentage' => round($percentage, 2),
                'isPercentage' => $isPercentage,
                'hasCode' => $code !== null && $code !== '',
                'promotionId' => $payload['promotionId'] ?? null,
            ];
        }

        return $discounts;
    }
}
