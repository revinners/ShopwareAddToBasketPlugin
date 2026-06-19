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
    #[Route('/cart-info', name: 'frontend.cart_info', defaults: ['XmlHttpRequest' => 'true'], methods: ['GET'])]
    public function cartInfo(Cart $cart, SalesChannelContext $channelContext): Response
    {
        $lineItems = $cart->getLineItems();
        $taxStatus = $cart->getPrice()?->getTaxStatus() ?? CartPrice::TAX_STATE_GROSS;

        $quantity = 0;
        $grossPrice = 0.0;
        $netPrice = 0.0;

        // Calculate only the cart items themselves: product line items.
        // Excludes shipping and the battery deposit
        // The totals reflect the value of the products only
        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
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
        ]);
    }
}
