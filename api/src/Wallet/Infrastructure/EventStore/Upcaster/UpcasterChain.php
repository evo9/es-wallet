<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore\Upcaster;

/**
 * Applies each registered upcaster in turn, checking `supports()` against the
 * currently-resolved version at each step — so a v1->v2->v3 chain resolves correctly as
 * long as upcasters are registered in ascending version order. Defaults to an empty list
 * (pure pass-through), which is what every DbalEventStore built without DI still gets.
 */
final class UpcasterChain
{
    /**
     * @param iterable<Upcaster> $upcasters
     */
    public function __construct(
        private readonly iterable $upcasters = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{event_version: int, payload: array<string, mixed>}
     */
    public function upcast(string $eventType, int $eventVersion, array $payload): array
    {
        $result = ['event_version' => $eventVersion, 'payload' => $payload];

        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->supports($eventType, $result['event_version'])) {
                $result = $upcaster->upcast($result['payload']);
            }
        }

        return $result;
    }
}
