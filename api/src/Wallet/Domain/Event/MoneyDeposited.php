<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Event;

use App\Wallet\Domain\ValueObject\WalletId;

/**
 * v2 schema — v1 (without $source) exists only as historical payload shape in the
 * event store and is upcast on read; it does not live in code (see task 07).
 */
final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(
        public WalletId $walletId,
        public int $amount,
        public string $currency,
        public string $source,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }

    public function aggregateId(): WalletId
    {
        return $this->walletId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function eventVersion(): int
    {
        return 2;
    }
}
