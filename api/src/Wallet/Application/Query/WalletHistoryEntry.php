<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

final readonly class WalletHistoryEntry
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventType,
        public \DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
    }
}
