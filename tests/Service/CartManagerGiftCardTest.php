<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revinners\AddToBasketPlugin\Service\CartManager;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * This route replaces the native form submit, so it is the only thing that can carry a gift card's
 * amount into the cart. Both facts under test broke the storefront when they knew the old plugin's
 * payload key alone: RevinnersVoucher cards arrived with no value and were dropped, so the "add to
 * cart" button looked like it did nothing, and two cards of different amounts collapsed into one
 * position priced at the first card's value.
 *
 * The methods are private because nothing outside the manager should decide the payload shape; they
 * are reached by reflection rather than by assembling the whole cart stack around them.
 */
class CartManagerGiftCardTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod(CartManager::class, $method);

        // No constructor: this exercises pure payload logic, and building the real dependency graph
        // (cart service, factory registry, session, plugin loader) would test Shopware, not this.
        $instance = (new \ReflectionClass(CartManager::class))->newInstanceWithoutConstructor();

        return $reflection->invokeArgs($instance, $args);
    }

    public function testAnOrdinaryLineItemIsNotTreatedAsAGiftCard(): void
    {
        $lineItem = new LineItem('a', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-a');

        self::assertFalse($this->invoke('isGiftCard', [$lineItem]));
    }

    public function testTheOldPluginsPayloadStillMarksAGiftCard(): void
    {
        $lineItem = new LineItem('a', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-a');
        $lineItem->setPayloadValue('netiNextEasyCoupon', ['voucherValue' => 100.0]);

        self::assertTrue($this->invoke('isGiftCard', [$lineItem]));
    }

    public function testTheNewPluginsPayloadAlsoMarksAGiftCard(): void
    {
        $lineItem = new LineItem('a', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-a');
        $lineItem->setPayloadValue('revVoucher', ['voucherValue' => 100.0]);

        // Without this the merge guard let a second card of a different amount be folded into the
        // first position, and the customer paid the first card's value twice.
        self::assertTrue($this->invoke('isGiftCard', [$lineItem]));
    }

    public function testTheAmountIsWrittenForBothPlugins(): void
    {
        $lineItem = new LineItem('a', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-a');

        $this->invoke('setGiftCardPayload', [$lineItem, 250.0, 'Wszystkiego najlepszego']);

        // RevinnersVoucher names the note `deliveryMessage`, matching the field its form posts;
        // NetiNextEasyCoupon calls the same thing `voucherMessage`. Each plugin reads only its own
        // key, and only for products it owns, so writing both cannot double-apply anything.
        self::assertSame(
            ['voucherValue' => 250.0, 'deliveryMessage' => 'Wszystkiego najlepszego'],
            $lineItem->getPayloadValue('revVoucher'),
        );
        self::assertSame(
            ['voucherValue' => 250.0, 'voucherMessage' => 'Wszystkiego najlepszego'],
            $lineItem->getPayloadValue('netiNextEasyCoupon'),
        );
    }

    public function testAMissingMessageIsCarriedAsNullRatherThanOmitted(): void
    {
        $lineItem = new LineItem('a', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-a');

        $this->invoke('setGiftCardPayload', [$lineItem, 50.0, null]);

        $payload = $lineItem->getPayloadValue('revVoucher');

        self::assertSame(50.0, $payload['voucherValue']);
        self::assertArrayHasKey('deliveryMessage', $payload);
        self::assertNull($payload['deliveryMessage']);
    }
}
