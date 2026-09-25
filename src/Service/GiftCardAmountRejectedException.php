<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Service;

/**
 * The gift-card plugin refused the amount of a card this route was asked to add, so the card was
 * taken back out of the cart instead of being left there at 0.00.
 */
class GiftCardAmountRejectedException extends \RuntimeException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct(sprintf('Gift card %s was rejected for an invalid amount and removed from the cart', $sku));
    }
}
