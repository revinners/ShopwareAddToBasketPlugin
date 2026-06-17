<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Storefront\Controller;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
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

        $quantity = 0;
        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $quantity += $lineItem->getQuantity();
            }
        }

        $cartPrice = $cart->getPrice();
        $totalPrice = $cartPrice !== null ? $cartPrice->getTotalPrice() : 0.0;
        $netPrice = $cartPrice !== null ? $cartPrice->getNetPrice() : 0.0;

        return new JsonResponse([
            'success' => true,
            'count' => $quantity,
            'lineItemCount' => $lineItems->count(),
            'totalPrice' => number_format($totalPrice, 2, '.', ''),
            'netPrice' => number_format($netPrice, 2, '.', ''),
            'currencyId' => $channelContext->getCurrencyId(),
        ]);
    }
}
