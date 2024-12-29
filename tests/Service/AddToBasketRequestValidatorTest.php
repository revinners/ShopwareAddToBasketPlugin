<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revinners\AddToBasketPlugin\DTO\AddToBasketRequest;
use Revinners\AddToBasketPlugin\Service\AddToBasketRequestValidator;
use Symfony\Component\Validator\Validation;

class AddToBasketRequestValidatorTest extends TestCase
{
    protected function createValidator(): AddToBasketRequestValidator
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        return new AddToBasketRequestValidator($validator);
    }

    public function testExample(): void
    {
        $requestValidator = $this->createValidator();

        $this->assertNotNull($requestValidator);
    }

    public function testValidateReturnsNoErrorsForValidRequest(): void
    {
        $validator = $this->createValidator();

        $request = new AddToBasketRequest('SW10001', 10);

        $errors = $validator->validate($request);

        $this->assertEmpty($errors, 'Expected no validation errors for a valid request.');
    }

    public function testValidateReturnsErrorsForInvalidRequest(): void
    {
        $validator = $this->createValidator();

        $request = new AddToBasketRequest('', -1);

        $errors = $validator->validate($request);

        $this->assertNotEmpty($errors, 'Expected validation errors, but got none.');
        $this->assertContains('SKU is required', $errors);
        $this->assertContains('Quantity must be a positive integer', $errors);
        $this->assertCount(2, $errors, 'Expected exactly 2 validation errors.');
    }

}
