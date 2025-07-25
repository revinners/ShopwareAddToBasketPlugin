<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class AddToBasketRequest
{
    #[Assert\NotBlank(message: 'SKU is required')]
    private string $sku;

    #[Assert\NotBlank(message: 'Quantity is required')]
    #[Assert\Positive(message: 'Quantity must be a positive integer')]
    private int $quantity;

    #[Assert\Assert\PositiveOrZero(message: 'Quantity must be a positive integer')]
    private float $amount;

    public function __construct(string $sku, int $quantity, float $amount = 0.0)
    {
        $this->sku = $sku;
        $this->quantity = $quantity;
        $this->amount = $amount;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }
}
