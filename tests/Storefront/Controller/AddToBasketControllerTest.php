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
            ->method('addToCart');

        $controller = $this->createController($validator, $productFinder, $cartManager);

        $request = $this->createRequest(['sku' => 'existing-sku', 'qty' => '2']);
        $cart = new Cart('test-cart');
        $context = $this->createMock(Context::class);
        $channelContext = $this->createMock(SalesChannelContext::class);

        $response = $controller->addToBasket($request, $cart, $context, $channelContext);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $content = $this->decodeJsonResponse($response);

        $this->assertTrue($content['success']);
        $this->assertEquals('Product added to the basket', $content['message']);
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
