<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the raw event store — audit "for free" (spec 4.4), unlike GetBalance which reads
 * only the projection. Depends directly on Infrastructure's event-store classes for the
 * same reason as GetBalanceHandler: a read-only audit query has no write-side invariant
 * to protect behind a port.
 */
#[AsMessageHandler]
final readonly class GetWalletHistoryHandler
{
    public function __construct(
        private DbalEventStore $eventStore,
        private EventSerializer $serializer,
    ) {
    }

    /**
     * @return WalletHistoryEntry[]
     */
    public function __invoke(GetWalletHistory $query): array
    {
        $entries = [];

        foreach ($this->eventStore->load($query->walletId) as $event) {
            $serialized = $this->serializer->serialize($event);
            $entries[] = new WalletHistoryEntry($serialized['event_type'], $event->occurredAt(), $serialized['payload']);
        }

        return $entries;
    }
}
