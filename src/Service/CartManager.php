<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CartManager
{
    public function __construct(
        private readonly LineItemFactoryRegistry $factory,
        private readonly CartService $cartService,
    ) {
    }

    public function addToCart(Cart $cart, ProductEntity $product, int $quantity, SalesChannelContext $channelContext): void
    {
        $existingLineItem = null;
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getReferencedId() === $product->getId() &&
                $lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $existingLineItem = $lineItem;

                break;
            }
        }

        if ($existingLineItem) {
            $existingLineItem->setQuantity($existingLineItem->getQuantity() + $quantity);
            $this->cartService->recalculate($cart, $channelContext);
        } else {
            $lineItem = $this->factory->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $product->getId(),
                'quantity' => $quantity,
            ], $channelContext);

            $this->cartService->add($cart, $lineItem, $channelContext);
        }
    }
}
