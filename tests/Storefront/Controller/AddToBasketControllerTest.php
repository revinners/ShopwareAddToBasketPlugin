<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Tests\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revinners\AddToBasketPlugin\Service\AddToBasketRequestValidator;
use Revinners\AddToBasketPlugin\Service\CartManager;
use Revinners\AddToBasketPlugin\Service\ProductFinder;
use Revinners\AddToBasketPlugin\Storefront\Controller\AddToBasketController;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\Cart\ProductNotFoundError;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AddToBasketControllerTest extends TestCase
{
    private function createValidatorMock(array $validationErrors = []): AddToBasketRequestValidator
    {
        $validator = $this->createMock(AddToBasketRequestValidator::class);
        $validator->method('validate')->willReturn($validationErrors);

        return $validator;
    }

    private function createProductFinderMock(?ProductEntity $product = null): ProductFinder
    {
        $productFinder = $this->createMock(ProductFinder::class);
        $productFinder->method('findBySku')->willReturn($product);

        return $productFinder;
    }

    private function createCartManagerMock(): CartManager
    {
        $cartManager = $this->createMock(CartManager::class);

        return $cartManager;
    }

    private function createController(
        AddToBasketRequestValidator $validator,
        ProductFinder $productFinder,
        CartManager $cartManager,
        ?LoggerInterface $logger = null,
    ): AddToBasketController {
        return new AddToBasketController($validator, $productFinder, $cartManager, $logger ?? new NullLogger());
    }

    private function createRequest(array $queryParams): Request
    {
        return new Request($queryParams);
    }

    private function createProductEntity(string $id): ProductEntity
    {
        $product = new ProductEntity();
        $product->setId($id);

        return $product;
    }

    private function decodeJsonResponse(Response $response): array
    {
        return json_decode($response->getContent(), true);
    }

    public function testAddToBasketValidationFails(): void
    {
        $validator = $this->createValidatorMock(['SKU is required']);
        $productFinder = $this->createProductFinderMock();
        $cartManager = $this->createCartManagerMock();
        $controller = $this->createController($validator, $productFinder, $cartManager);

        $request = $this->createRequest(['sku' => '', 'qty' => '0']);
        $cart = new Cart('test-cart');
        $context = $this->createMock(Context::class);
        $channelContext = $this->createMock(SalesChannelContext::class);

        $response = $controller->addToBasket($request, $cart, $context, $channelContext);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $content = $this->decodeJsonResponse($response);

        $this->assertFalse($content['success']);
        $this->assertEquals('Validation failed', $content['message']);
        $this->assertContains('SKU is required', $content['errors']);
    }

    public function testAddToBasketProductNotFound(): void
    {
        $validator = $this->createValidatorMock();
        $productFinder = $this->createProductFinderMock();
        $cartManager = $this->createCartManagerMock();
        $controller = $this->createController($validator, $productFinder, $cartManager);

        $request = $this->createRequest(['sku' => 'non-existing-sku', 'qty' => '1']);
        $cart = new Cart('test-cart');
        $context = $this->createMock(Context::class);
        $channelContext = $this->createMock(SalesChannelContext::class);

        $response = $controller->addToBasket($request, $cart, $context, $channelContext);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $content = $this->decodeJsonResponse($response);

        $this->assertFalse($content['success']);
        $this->assertEquals('Product with SKU non-existing-sku not found', $content['message']);
    }

    public function testAddToBasketSuccess(): void
    {
        $validator = $this->createValidatorMock();
        $product = $this->createProductEntity('product-id');
        $productFinder = $this->createProductFinderMock($product);

        $cartManager = $this->createCartManagerMock();
        $cartManager->expects($this->once())
            ->method('addToCart')
            ->willReturnCallback(fn (Cart $cart) => $this->putInCart($cart, 'line-1', 'product-id', 123.0, 23.0, 2));

        $controller = $this->createController($validator, $productFinder, $cartManager);

        $request = $this->createRequest(['sku' => 'existing-sku', 'qty' => '2']);
        $cart = new Cart('test-cart');
        $context = $this->createMock(Context::class);
        $channelContext = $this->createChannelContext(CartPrice::TAX_STATE_GROSS);

        $response = $controller->addToBasket($request, $cart, $context, $channelContext);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $content = $this->decodeJsonResponse($response);

        $this->assertTrue($content['success']);
        $this->assertEquals('Product added to the basket', $content['message']);
        $this->assertSame('246.00', $content['price']);
    }

    public function testThePriceInAGrossContextIsTheUnitPriceWithoutTaxAddedAgain(): void
    {
        // A 1000 zł gift card at 23 % VAT used to answer 1230.00: the gross unit price was
        // multiplied by the tax rate once more.
        $content = $this->addWithCart(CartPrice::TAX_STATE_GROSS, 1, function (Cart $cart): LineItem {
            return $this->putInCart($cart, 'rev-voucher-a', 'product-id', 1000.0, 23.0, 1);
        });

        $this->assertSame('1000.00', $content['price']);
    }

    public function testThePriceInANetContextAddsTheTaxToTheNetUnitPrice(): void
    {
        // B2B: the calculated unit price is net, 100 + 23 % = 123.00 per piece.
        $content = $this->addWithCart(CartPrice::TAX_STATE_NET, 2, function (Cart $cart): LineItem {
            return $this->putInCart($cart, 'line-1', 'product-id', 100.0, 23.0, 2, true);
        });

        $this->assertSame('246.00', $content['price']);
    }

    public function testASecondGiftCardReportsItsOwnPriceNotTheFirstOnes(): void
    {
        $content = $this->addWithCart(CartPrice::TAX_STATE_GROSS, 1, function (Cart $cart): LineItem {
            $this->putInCart($cart, 'rev-voucher-first', 'product-id', 1000.0, 23.0, 1);

            return $this->putInCart($cart, 'rev-voucher-second', 'product-id', 250.0, 23.0, 1);
        });

        $this->assertSame('250.00', $content['price']);
    }

    private function addWithCart(string $taxState, int $qty, callable $fill): array
    {
        $product = $this->createProductEntity('product-id');
        $cartManager = $this->createCartManagerMock();
        $cartManager->method('addToCart')->willReturnCallback(static fn (Cart $cart) => $fill($cart));

        $controller = $this->createController($this->createValidatorMock(), $this->createProductFinderMock($product), $cartManager);

        $response = $controller->addToBasket(
            $this->createRequest(['sku' => 'SW10020', 'qty' => (string) $qty]),
            new Cart('test-cart'),
            $this->createMock(Context::class),
            $this->createChannelContext($taxState),
        );

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        return $this->decodeJsonResponse($response);
    }

    private function createChannelContext(string $taxState): SalesChannelContext
    {
        $channelContext = $this->createMock(SalesChannelContext::class);
        $channelContext->method('getTaxState')->willReturn($taxState);

        return $channelContext;
    }

    /**
     * A priced line as the cart calculator leaves it: in a gross context the unit price already
     * contains the tax, in a net one the tax sits beside it.
     */
    private function putInCart(Cart $cart, string $id, string $productId, float $unitPrice, float $taxRate, int $quantity, bool $net = false): LineItem
    {
        $total = $unitPrice * $quantity;
        $tax = $net ? $total * $taxRate / 100 : $total - $total / (1 + $taxRate / 100);

        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, $quantity);
        $lineItem->setStackable(true);
        $lineItem->setPrice(new CalculatedPrice(
            $unitPrice,
            $total,
            new CalculatedTaxCollection([new CalculatedTax($tax, $taxRate, $total)]),
            new TaxRuleCollection(),
            $quantity,
        ));
        $cart->add($lineItem);

        return $lineItem;
    }

    public function testAddToBasketLogsCartErrorsWhenCartDropsTheLineItem(): void
    {
        $validator = $this->createValidatorMock();
        $product = $this->createProductEntity('product-id');
        $productFinder = $this->createProductFinderMock($product);

        // The cart processors (e.g. an inactive product) rejected the line item:
        // nothing lands in the cart, only a cart error explaining why.
        $cartManager = $this->createCartManagerMock();
        $cartManager->method('addToCart')
            ->willReturnCallback(static function (Cart $cart): void {
                $cart->addErrors(new ProductNotFoundError('product-id'));
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Could not retrieve line item from cart'),
                $this->callback(static function (array $context): bool {
                    return $context['sku'] === '9079EY000100' &&
                        $context['originalSku'] === '908908586300' &&
                        $context['qty'] === 1 &&
                        $context['cartErrors'][0]['key'] === 'product-not-found';
                }),
            );

        $controller = $this->createController($validator, $productFinder, $cartManager, $logger);

        $request = $this->createRequest(['sku' => '9079EY000100', 'qty' => '1', 'originalSku' => '908908586300']);
        $cart = new Cart('test-cart');
        $context = $this->createMock(Context::class);
        $channelContext = $this->createMock(SalesChannelContext::class);

        $response = $controller->addToBasket($request, $cart, $context, $channelContext);

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $content = $this->decodeJsonResponse($response);

        $this->assertFalse($content['success']);
        $this->assertEquals('Could not retrieve line item from cart', $content['message']);
        $this->assertEquals('product-not-found', $content['errors'][0]['key']);
        $this->assertNotEmpty($content['errors'][0]['message']);
    }
}
