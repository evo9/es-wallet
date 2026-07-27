<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\Persistence;

use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\Exception\WalletNotFoundException;
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

final class EventSourcedWalletRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DbalSnapshotStore $snapshotStore;
    private EventSourcedWalletRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE wallet_events');
        $this->connection->executeStatement('TRUNCATE TABLE wallet_snapshots');

        $this->snapshotStore = new DbalSnapshotStore($this->connection);
        $this->repository = new EventSourcedWalletRepository(
            new DbalEventStore(
                $this->connection,
                new EventSerializer(new EventTypeRegistry()),
                new UpcasterChain(),
            ),
            self::getContainer()->get(MessageBusInterface::class),
            $this->snapshotStore,
        );
    }

    public function test_getting_a_nonexistent_wallet_throws(): void
    {
        $this->expectException(WalletNotFoundException::class);

        $this->repository->get(WalletId::generate());
    }

    public function test_getting_an_existing_wallet_reconstitutes_it_from_its_events(): void
    {
        $walletId = WalletId::generate();

        $eventStore = new DbalEventStore(
            $this->connection,
            new EventSerializer(new EventTypeRegistry()),
            new UpcasterChain(),
        );
        $eventStore->append($walletId, 0, [
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
        ]);

        $wallet = $this->repository->get($walletId);

        self::assertSame([
            'walletId' => $walletId->toString(),
            'currency' => 'EUR',
            'balance' => 100,
            'held' => 0,
            'holds' => [],
            'closed' => false,
        ], $wallet->toSnapshotState());
    }

    public function test_saving_a_new_wallet_persists_its_events(): void
    {
        $walletId = WalletId::generate();

        $this->repository->save(Wallet::open($walletId, 'EUR'));

        $reloaded = $this->repository->get($walletId);

        self::assertSame([
            'walletId' => $walletId->toString(),
            'currency' => 'EUR',
            'balance' => 0,
            'held' => 0,
            'holds' => [],
            'closed' => false,
        ], $reloaded->toSnapshotState());
    }

    public function test_saving_after_loading_appends_only_the_new_events(): void
    {
        $walletId = WalletId::generate();
        $this->repository->save(Wallet::open($walletId, 'EUR'));

        $wallet = $this->repository->get($walletId);
        $wallet->deposit(new Money(50, 'EUR'), 'topup');
        $this->repository->save($wallet);

        $reloaded = $this->repository->get($walletId);

        self::assertSame(50, $reloaded->toSnapshotState()['balance']);
    }

    public function test_getting_a_wallet_uses_the_snapshot_instead_of_full_replay(): void
    {
        $walletId = WalletId::generate();
        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $this->repository->save($wallet); // version 2

        $this->snapshotStore->save($walletId, 2, $wallet->toSnapshotState());

        // Simulate history compaction after the snapshot was taken: if get() ignored
        // the snapshot and replayed from version 0, it would find nothing here and
        // either throw or reconstruct an empty wallet — proving it actually used it.
        $this->connection->executeStatement(
            'DELETE FROM wallet_events WHERE aggregate_id = :walletId AND version <= 2',
            ['walletId' => $walletId->toString()],
        );

        $reloaded = $this->repository->get($walletId);

        self::assertSame(100, $reloaded->toSnapshotState()['balance']);
    }

    public function test_getting_a_wallet_falls_back_to_full_replay_when_the_snapshot_schema_is_incompatible(): void
    {
        $walletId = WalletId::generate();
        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $this->repository->save($wallet);

        // A snapshot is a cache, not the source of truth: an incompatible shape (e.g.
        // after a Wallet state refactor) must not break loading — see README.
        $this->snapshotStore->save($walletId, 2, ['unexpected' => 'shape']);

        $reloaded = $this->repository->get($walletId);

        self::assertSame(100, $reloaded->toSnapshotState()['balance']);
        self::assertNull($this->snapshotStore->load($walletId), 'Incompatible snapshot should have been dropped.');
    }
}
