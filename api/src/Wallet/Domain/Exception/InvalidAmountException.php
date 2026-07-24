<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Exception;

final class InvalidAmountException extends \DomainException
{
    public function __construct(int $amount)
    {
        parent::__construct(sprintf('Amount must be positive, got %d.', $amount));
    }
}
