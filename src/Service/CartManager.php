<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Service;

use Revinners\AddToBasketPlugin\DTO\AddToBasketRequest;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\KernelPluginLoader;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class CartManager
{
    private Session $session;

    public function __construct(
        private readonly LineItemFactoryRegistry  $factory,
        private readonly CartService              $cartService,
        private readonly EventDispatcherInterface $eventDispatcher,
        SessionFactoryInterface                   $sessionFactory,
        private readonly KernelPluginLoader       $pluginLoader
    )
    {
        $this->session = $sessionFactory->createSession();
    }

    public function addToCart(Cart $cart, ProductEntity $product, AddToBasketRequest $dto, SalesChannelContext $channelContext): void
    {
        $quantity = $dto->getQuantity();
        $amount = $dto->getAmount();
        $message = $dto->getMessage();
        $existingLineItem = null;
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getReferencedId() === $product->getId() &&
                $lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE &&
                !$lineItem->hasPayloadValue('netiNextEasyCoupon')) {
                $existingLineItem = $lineItem;
                break;
            }
        }

        $items = [];

        if ($existingLineItem) {
            $existingLineItem->setQuantity($existingLineItem->getQuantity() + $quantity);
            $this->cartService->recalculate($cart, $channelContext);
            $items[] = $existingLineItem;
        } else {
            $lineItem = $this->factory->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $product->getId(),
                'quantity' => $quantity,
            ], $channelContext);

            if (!empty($amount)) {
                $lineItem->setPayloadValue('netiNextEasyCoupon', ['voucherValue' => $amount, 'voucherMessage' => $message]);
            }

            $this->cartService->add($cart, $lineItem, $channelContext);
            $items[] = $lineItem;
        }

        if ($this->isPluginActive('Wbm\TagManagerAnalytics\WbmTagManagerAnalytics')) {
            $this->session->set('wbm-stored-shouldUpdate', 'cartaddprice');
            $this->session->set('rev-addedCartItems', array_map(static function (LineItem $item) {
                return [
                    'id' => $item->getReferencedId(),
                    'quantity' => $item->getQuantity(),
                ];
            }, $items));
        }

        $this->eventDispatcher->dispatch(new AfterLineItemAddedEvent($items, $cart, $channelContext));
        $this->eventDispatcher->dispatch(new CartChangedEvent($cart, $channelContext));
    }

    private function isPluginActive(string $pluginName): bool
    {
        $plugin = $this->pluginLoader->getPluginInstance($pluginName);
        return $plugin !== null && $plugin->isActive();
    }
}