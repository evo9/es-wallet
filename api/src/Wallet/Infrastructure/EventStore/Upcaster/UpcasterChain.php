<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore\Upcaster;

/**
 * Pass-through for now — no upcasters are registered yet (task 07 adds v1->v2 for
 * MoneyDeposited). Kept as the extension point DbalEventStore::load() already calls,
 * so introducing real upcasters later needs no change to the read path.
 */
final class UpcasterChain
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array{event_version: int, payload: array<string, mixed>}
     */
    public function upcast(string $eventType, int $eventVersion, array $payload): array
    {
        return [
            'event_version' => $eventVersion,
            'payload' => $payload,
        ];
    }
}
