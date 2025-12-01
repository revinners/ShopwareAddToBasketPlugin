<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class AddToBasketRequest
{
    #[Assert\NotBlank(message: 'SKU is required')]
    private string $sku;

    #[Assert\Length(max: 50, maxMessage: 'Message cannot exceed 50 characters')]
    private ?string $originalSku;

    #[Assert\NotBlank(message: 'Quantity is required')]
    #[Assert\Positive(message: 'Quantity must be a positive integer')]
    private int $quantity;

    #[Assert\Assert\PositiveOrZero(message: 'Quantity must be a positive integer')]
    private float $amount;

    #[Assert\Length(max: 255, maxMessage: 'Message cannot exceed 255 characters')]
    private ?string $message;

    public function __construct(string $sku, int $quantity, float $amount = 0.0, ?string $message = null, ?string $originalSku = null)
    {
        $this->sku = $sku;
        $this->originalSku = $originalSku;
        $this->quantity = $quantity;
        $this->amount = $amount;
        $this->message = $message;
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getOriginalSku(): ?string
    {
        return $this->originalSku;
    }
}
