<?php

declare(strict_types=1);

namespace Revinners\AddToBasketPlugin\Service;

use Revinners\AddToBasketPlugin\DTO\AddToBasketRequest;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AddToBasketRequestValidator
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * @return array<string>
     */
    public function validate(AddToBasketRequest $request): array
    {
        $violations = $this->validator->validate($request);

        if (count($violations) === 0) {
            return [];
        }

        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = $violation->getMessage();
        }

        return $errors;
    }
}
