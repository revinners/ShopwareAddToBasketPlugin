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
                in_array($lineItem->getType(), [LineItem::PRODUCT_LINE_ITEM_TYPE, 'revinners_bundle'], true) &&
                !$this->isGiftCard($lineItem)) {
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
                $this->setGiftCardPayload($lineItem, (float) $amount, $message);
            }

            $originalSku = $dto->getOriginalSku();
            if (!empty($originalSku)) {
                $lineItem->setPayloadValue('originalSku', $originalSku);
            }

            $calculated = $this->cartService->add($cart, $lineItem, $channelContext);

            if ($this->hasRejectedGiftCardAmount($calculated, $lineItem->getId())) {
                // Left in place, the card would sit in the cart at 0.00 behind a blocking error, and
                // the storefront would still open its "added to cart" modal for it. Taking it back out
                // and failing the request lets the product page say what is wrong, next to the field.
                $this->cartService->remove($calculated, $lineItem->getId(), $channelContext);

                throw new GiftCardAmountRejectedException($dto->getSku());
            }

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

        //Wymagane do poprawnego działania event subscriberów, które nasłuchują na dodanie produktu do koszyka
        $this->eventDispatcher->dispatch(new AfterLineItemAddedEvent($items, $cart, $channelContext));
        $this->eventDispatcher->dispatch(new CartChangedEvent($cart, $channelContext));
    }

    /**
     * Payload keys of the gift-card plugins this shop can be running.
     *
     * Two of them, because RevinnersVoucher is replacing NetiNextEasyCoupon and both are installed
     * during the changeover. Each plugin reads only its own key and only for products it owns, so
     * writing both is safe; the `netiNextEasyCoupon` entry can go once that plugin is uninstalled.
     *
     * The value keys differ: RevinnersVoucher names the note `deliveryMessage`, matching the field
     * its storefront form posts.
     */
    private const GIFT_CARD_PAYLOADS = [
        'revVoucher' => 'deliveryMessage',
        'netiNextEasyCoupon' => 'voucherMessage',
    ];

    /**
     * A gift card must never be merged into an existing position.
     *
     * Its amount lives on the line item, so bumping the quantity of the one already in the cart
     * would sell a second card at the first one's value. This used to test the EasyCoupon key alone,
     * which meant two RevinnersVoucher cards of different amounts silently collapsed into one.
     */
    private function isGiftCard(LineItem $lineItem): bool
    {
        foreach (array_keys(self::GIFT_CARD_PAYLOADS) as $key) {
            if ($lineItem->hasPayloadValue($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The amount typed on the product page, in the shape each gift-card plugin expects.
     *
     * This route replaces the native form submit, so it is the only thing that can carry the amount
     * at all: without it the card reaches the cart with no value and is dropped there, which in the
     * storefront looks exactly like an "add to cart" button that does nothing.
     */
    private function setGiftCardPayload(LineItem $lineItem, float $amount, ?string $message): void
    {
        foreach (self::GIFT_CARD_PAYLOADS as $key => $messageKey) {
            $lineItem->setPayloadValue($key, [
                'voucherValue' => $amount,
                $messageKey => $message,
            ]);
        }
    }

    /**
     * Cart error RevinnersVoucher raises for a card whose amount is missing or outside what the
     * product allows. The voucher plugin owns the range and selection rules, so its verdict is read
     * here rather than re-implementing them.
     */
    private const GIFT_CARD_VALUE_ERROR_KEY = 'rev-voucher-value-invalid';

    private function hasRejectedGiftCardAmount(Cart $cart, string $lineItemId): bool
    {
        foreach ($cart->getErrors() as $error) {
            if ($error->getMessageKey() === self::GIFT_CARD_VALUE_ERROR_KEY
                && ($error->getParameters()['lineItemId'] ?? null) === $lineItemId) {
                return true;
            }
        }

        return false;
    }

    private function isPluginActive(string $pluginName): bool
    {
        $plugin = $this->pluginLoader->getPluginInstance($pluginName);
        return $plugin !== null && $plugin->isActive();
    }
}