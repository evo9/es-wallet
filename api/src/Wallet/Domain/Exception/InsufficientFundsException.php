<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Exception;

final class InsufficientFundsException extends \DomainException
{
    public function __construct(int $requested, int $available)
    {
        parent::__construct(sprintf('Requested %d, only %d available.', $requested, $available));
    }
}
