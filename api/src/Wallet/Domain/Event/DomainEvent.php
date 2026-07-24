<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Event;

use App\Wallet\Domain\ValueObject\WalletId;

interface DomainEvent
{
    public function aggregateId(): WalletId;

    public function occurredAt(): \DateTimeImmutable;

    /**
     * Schema version of this event's payload (upcasting), not the aggregate version.
     */
    public function eventVersion(): int;
}
