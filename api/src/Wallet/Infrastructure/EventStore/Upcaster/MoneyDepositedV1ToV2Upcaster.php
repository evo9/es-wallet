<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore\Upcaster;

/**
 * v1 money_deposited payloads lack `source` (see spec 2.4/5). Only the current (v2)
 * MoneyDeposited class lives in code — v1 exists solely as a payload shape in the store.
 */
final class MoneyDepositedV1ToV2Upcaster implements Upcaster
{
    public function supports(string $eventType, int $eventVersion): bool
    {
        return $eventType === 'money_deposited' && $eventVersion === 1;
    }

    public function upcast(array $payload): array
    {
        return [
            'event_version' => 2,
            'payload' => [...$payload, 'source' => 'unknown'],
        ];
    }
}
