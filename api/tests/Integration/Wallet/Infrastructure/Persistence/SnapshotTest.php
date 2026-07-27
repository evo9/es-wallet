<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\Persistence;

use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use App\Wallet\Infrastructure\Persistence\EventSourcedWalletRepository;
use App\Wallet\Infrastructure\Snapshot\DbalSnapshotStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Spec 9.2: 120 events -> a snapshot lands at 100 -> loading afterwards only replays the
 * <=20-event tail, not the full 120-event history.
 */
final class SnapshotTest extends KernelTestCase
{
    private Connection $connection;
    private DbalSnapshotStore $snapshotStore;
    private DbalEventStore $eventStore;
    private EventSourcedWalletRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE wallet_events');
        $this->connection->executeStatement('TRUNCATE TABLE wallet_snapshots');

        $this->eventStore = new DbalEventStore($this->connection, new EventSerializer(new EventTypeRegistry()), new UpcasterChain());
        $this->snapshotStore = new DbalSnapshotStore($this->connection);
        $this->repository = new EventSourcedWalletRepository(
            $this->eventStore,
            self::getContainer()->get(MessageBusInterface::class),
            $this->snapshotStore,
        );
    }

    public function test_a_snapshot_is_written_at_the_threshold_and_load_only_replays_the_tail(): void
    {
        $walletId = WalletId::generate();

        $this->repository->save(Wallet::open($walletId, 'EUR')); // version 1

        for ($i = 2; $i <= 120; $i++) { // versions 2..120 -> 120 events total
            $wallet = $this->repository->get($walletId);
            $wallet->deposit(new Money(1, 'EUR'), 'topup');
            $this->repository->save($wallet);
        }

        $snapshot = $this->snapshotStore->load($walletId);
        self::assertNotNull($snapshot);
        self::assertSame(100, $snapshot['version']);
        self::assertSame(99, $snapshot['state']['balance']); // open + 99 deposits by version 100

        $tail = iterator_to_array($this->eventStore->load($walletId, fromVersion: $snapshot['version']));
        self::assertCount(20, $tail);

        $wallet = $this->repository->get($walletId);
        self::assertSame(119, $wallet->toSnapshotState()['balance']);
        self::assertSame(120, $wallet->version());
    }
}
