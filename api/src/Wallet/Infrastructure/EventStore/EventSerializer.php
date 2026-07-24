<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore;

use App\Wallet\Domain\Event\DomainEvent;
use App\Wallet\Domain\ValueObject\WalletId;

final readonly class EventSerializer
{
    public function __construct(
        private EventTypeRegistry $registry,
    ) {
    }

    /**
     * @return array{event_type: string, event_version: int, payload: array<string, mixed>}
     */
    public function serialize(DomainEvent $event): array
    {
        $payload = get_object_vars($event);
        unset($payload['walletId'], $payload['occurredAt']);

        return [
            'event_type' => $this->registry->typeForClass($event::class),
            'event_version' => $event->eventVersion(),
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function deserialize(
        string $type,
        int $eventVersion,
        array $payload,
        WalletId $aggregateId,
        \DateTimeImmutable $occurredAt,
    ): DomainEvent {
        // $eventVersion is unused here: the upcaster chain already normalized $payload to
        // the current schema before this call — only the latest event class lives in code.
        $class = $this->registry->classForType($type);

        // Named args can't follow a spread in the same call, so build one array first.
        $constructorArgs = ['walletId' => $aggregateId, 'occurredAt' => $occurredAt] + $payload;

        return new $class(...$constructorArgs);
    }
}
