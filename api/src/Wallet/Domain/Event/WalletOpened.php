<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Event;

use App\Wallet\Domain\ValueObject\WalletId;

final readonly class WalletOpened implements DomainEvent
{
    public function __construct(
        public WalletId $walletId,
        public string $currency,
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
        return 1;
    }
}
