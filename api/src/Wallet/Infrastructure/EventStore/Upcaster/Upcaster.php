<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore\Upcaster;

/**
 * One step in the chain: upgrades a single event schema version to the next
 * (e.g. money_deposited v1 -> v2). Registered upcasters are tagged and collected into
 * UpcasterChain via DI (see services.yaml) — adding a new one needs no wiring change.
 */
interface Upcaster
{
    public function supports(string $eventType, int $eventVersion): bool;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{event_version: int, payload: array<string, mixed>}
     */
    public function upcast(array $payload): array;
}
