<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Persistence;

use App\Wallet\Domain\Exception\WalletNotFoundException;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Domain\WalletRepository;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\Projection\ProjectableEvent;
use App\Wallet\Infrastructure\Snapshot\DbalSnapshotStore;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class EventSourcedWalletRepository implements WalletRepository
{
    /**
     * After how many committed events a fresh snapshot is written (spec 6). A plain
     * class constant, not env-configurable — not asked for and easy to add later.
     */
    private const int SNAPSHOT_THRESHOLD = 50;

    public function __construct(
        private DbalEventStore $eventStore,
        private MessageBusInterface $bus,
        private DbalSnapshotStore $snapshotStore,
    ) {
    }

    public function get(WalletId $walletId): Wallet
    {
        $snapshot = $this->snapshotStore->load($walletId);

        if ($snapshot !== null) {
            try {
                $wallet = Wallet::fromSnapshot($snapshot['state'], $snapshot['version']);
                $wallet->applyHistory($this->eventStore->load($walletId, fromVersion: $snapshot['version']));

                return $wallet;
            } catch (\Throwable) {
                // Cache, not source of truth (spec 6): a shape incompatible with the
                // current Wallet (e.g. after refactoring its state) is simply dropped,
                // never migrated — fall through to a full replay below.
                $this->snapshotStore->delete($walletId);
            }
        }

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

        $versionBefore = $wallet->version() - count($events);
        $aggregateId = $events[0]->aggregateId();

        $this->eventStore->append($aggregateId, $versionBefore, $events);

        $version = $versionBefore;
        foreach ($events as $event) {
            ++$version;
            $this->bus->dispatch(new ProjectableEvent($event, $version));
        }

        $this->snapshotIfThresholdCrossed($wallet, $aggregateId, $versionBefore, $wallet->version());
    }

    /**
     * "Crossed a multiple of N" (not "== a multiple of N") so a save() batching several
     * events past a boundary in one go still snapshots exactly once.
     */
    private function snapshotIfThresholdCrossed(Wallet $wallet, WalletId $aggregateId, int $versionBefore, int $versionAfter): void
    {
        $crossed = intdiv($versionAfter, self::SNAPSHOT_THRESHOLD) > intdiv($versionBefore, self::SNAPSHOT_THRESHOLD);

        if ($crossed) {
            $this->snapshotStore->save($aggregateId, $versionAfter, $wallet->toSnapshotState());
        }
    }
}
