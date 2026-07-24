<?php

declare(strict_types=1);

namespace App\Wallet\Domain\ValueObject;

final readonly class Money
{
    public function __construct(
        private int $amount,
        private string $currency,
    ) {
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }
}
