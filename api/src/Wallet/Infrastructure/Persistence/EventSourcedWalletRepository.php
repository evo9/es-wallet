<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Persistence;

use App\Wallet\Domain\Exception\WalletNotFoundException;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Domain\WalletRepository;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\Projection\ProjectableEvent;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class EventSourcedWalletRepository implements WalletRepository
{
    public function __construct(
        private DbalEventStore $eventStore,
        private MessageBusInterface $bus,
    ) {
    }

    public function get(WalletId $walletId): Wallet
    {
        // Extension point for task 07: look up a snapshot here and load(fromVersion:
        // snapshot version) instead of 0, then Wallet::fromSnapshot() + apply the tail.
        $events = iterator_to_array($this->eventStore->load($walletId, fromVersion: 0));

        if ($events === []) {
            throw new WalletNotFoundException($walletId);
        }

        return Wallet::reconstitute($events);
    }

    public function save(Wallet $wallet): void
    {
        $events = $wallet->pullUncommittedEvents();

        if ($events === []) {
            return;
        }

        $expectedVersion = $wallet->version() - count($events);
        $aggregateId = $events[0]->aggregateId();

        $this->eventStore->append($aggregateId, $expectedVersion, $events);

        // Extension point for task 07: upsert a snapshot here once version crosses
        // the threshold — after the commit above, same as the dispatch below.
        $version = $expectedVersion;
        foreach ($events as $event) {
            ++$version;
            $this->bus->dispatch(new ProjectableEvent($event, $version));
        }
    }
}
