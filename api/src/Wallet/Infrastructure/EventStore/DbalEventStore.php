<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore;

use App\Wallet\Domain\Event\DomainEvent;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

final readonly class DbalEventStore
{
    public function __construct(
        private Connection $connection,
        private EventSerializer $serializer,
        private UpcasterChain $upcasterChain,
    ) {
    }

    /**
     * One INSERT per event inside a single transaction, versioned expectedVersion+1, +2, ...
     * Concurrency is enforced purely by the UNIQUE (aggregate_id, version) index — no locks.
     *
     * @param DomainEvent[] $events
     */
    public function append(WalletId $aggregateId, int $expectedVersion, array $events): void
    {
        $this->connection->beginTransaction();

        try {
            $version = $expectedVersion;

            foreach ($events as $event) {
                ++$version;
                $serialized = $this->serializer->serialize($event);

                try {
                    $this->connection->insert('wallet_events', [
                        'aggregate_id' => $aggregateId->toString(),
                        'version' => $version,
                        'event_type' => $serialized['event_type'],
                        'event_version' => $serialized['event_version'],
                        'payload' => $serialized['payload'],
                        'occurred_at' => $event->occurredAt(),
                    ], [
                        'payload' => Types::JSON,
                        'occurred_at' => Types::DATETIMETZ_IMMUTABLE,
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    throw new ConcurrencyException($aggregateId, $expectedVersion, $exception);
                }
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @return iterable<DomainEvent>
     */
    public function load(WalletId $aggregateId, int $fromVersion = 0): iterable
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT event_type, event_version, payload, occurred_at
                FROM wallet_events
                WHERE aggregate_id = :aggregateId AND version > :fromVersion
                ORDER BY version ASC
                SQL,
            [
                'aggregateId' => $aggregateId->toString(),
                'fromVersion' => $fromVersion,
            ],
        );

        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
            $occurredAt = new \DateTimeImmutable($row['occurred_at']);

            $upcasted = $this->upcasterChain->upcast($row['event_type'], (int) $row['event_version'], $payload);

            yield $this->serializer->deserialize(
                $row['event_type'],
                $upcasted['event_version'],
                $upcasted['payload'],
                $aggregateId,
                $occurredAt,
            );
        }
    }
}
