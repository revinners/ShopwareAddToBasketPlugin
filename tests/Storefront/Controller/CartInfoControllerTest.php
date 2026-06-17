<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Tests\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Revinners\AddToBasketPlugin\Storefront\Controller\CartInfoController;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedTaxCollection;
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

    private function createProductLineItem(string $id, int $quantity): LineItem
    {
        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity);

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
        $cart->add($this->createProductLineItem('product-a', 2));
        $cart->add($this->createProductLineItem('product-b', 3));
        $cart->setPrice(new CartPrice(
            100.36,
            123.45,
            123.45,
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
        $this->assertSame(5, $content['count']);
        $this->assertSame(2, $content['lineItemCount']);
        $this->assertSame('123.45', $content['totalPrice']);
        $this->assertSame('100.36', $content['netPrice']);
    }
}
