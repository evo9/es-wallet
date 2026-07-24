<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Exception;

final class CurrencyMismatchException extends \DomainException
{
    public function __construct(string $expected, string $actual)
    {
        parent::__construct(sprintf('Expected currency "%s", got "%s".', $expected, $actual));
    }
}
