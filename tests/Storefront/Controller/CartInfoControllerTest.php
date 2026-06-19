<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Tests\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Revinners\AddToBasketPlugin\Storefront\Controller\CartInfoController;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Response;

class CartInfoControllerTest extends TestCase
{
    private function createChannelContextMock(): SalesChannelContext
    {
        $channelContext = $this->createMock(SalesChannelContext::class);
        $channelContext->method('getCurrencyId')->willReturn('currency-id');

        return $channelContext;
    }

    private function createLineItem(string $id, string $type, int $quantity, float $totalPrice, float $taxAmount): LineItem
    {
        $lineItem = new LineItem($id, $type, $id, $quantity);
        $taxes = new CalculatedTaxCollection([new CalculatedTax($taxAmount, 23.0, $totalPrice)]);
        $unitPrice = $quantity > 0 ? $totalPrice / $quantity : $totalPrice;
        $lineItem->setPrice(new CalculatedPrice($unitPrice, $totalPrice, $taxes, new TaxRuleCollection(), $quantity));

        return $lineItem;
    }

    private function decodeJsonResponse(Response $response): array
    {
        return json_decode($response->getContent(), true);
    }

    public function testCartInfoEmptyCart(): void
    {
        $controller = new CartInfoController();

        $cart = new Cart('test-cart');
        $channelContext = $this->createChannelContextMock();

        $response = $controller->cartInfo($cart, $channelContext);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $content = $this->decodeJsonResponse($response);

        $this->assertTrue($content['success']);
        $this->assertSame(0, $content['count']);
        $this->assertSame(0, $content['lineItemCount']);
        $this->assertSame('0.00', $content['totalPrice']);
        $this->assertSame('0.00', $content['netPrice']);
        $this->assertSame('currency-id', $content['currencyId']);
    }

    public function testCartInfoWithItems(): void
    {
        $controller = new CartInfoController();

        $cart = new Cart('test-cart');
        // gross totals: a "product" type 100.00 (tax 20.00) + a "custom" type 23.45 (tax 3.45).
        // Both must count - the cart can hold several product-ish line item types.
        $cart->add($this->createLineItem('product-a', LineItem::PRODUCT_LINE_ITEM_TYPE, 2, 100.00, 20.00));
        $cart->add($this->createLineItem('custom-b', LineItem::CUSTOM_LINE_ITEM_TYPE, 3, 23.45, 3.45));
        // battery deposit is excluded from everything
        $cart->add($this->createLineItem('battery-deposit', 'battery_deposit', 1, 50.00, 0.00));
        // promotion lowers the price totals but is NOT counted as an item
        $cart->add($this->createLineItem('promo', LineItem::PROMOTION_LINE_ITEM_TYPE, 1, -30.00, -5.60));
        $cart->setPrice(new CartPrice(
            500.00,
            500.00,
            500.00,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        $channelContext = $this->createChannelContextMock();

        $response = $controller->cartInfo($cart, $channelContext);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $content = $this->decodeJsonResponse($response);

        $this->assertTrue($content['success']);
        // 2 (product) + 3 (custom); battery and promotion not counted as items
        $this->assertSame(5, $content['count']);
        $this->assertSame(4, $content['lineItemCount']);
        // gross: 100.00 + 23.45 - 30.00 (promotion), battery (50.00) excluded
        $this->assertSame('93.45', $content['totalPrice']);
        // net: (100.00 - 20.00) + (23.45 - 3.45) + (-30.00 - -5.60) = 75.60
        $this->assertSame('75.60', $content['netPrice']);
    }
}
