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

    public function __construct(string $sku, int $quantity)
    {
        $this->sku = $sku;
        $this->quantity = $quantity;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
