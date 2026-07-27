<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\Projection;

use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use App\Wallet\Infrastructure\Persistence\EventSourcedWalletRepository;
use App\Wallet\Infrastructure\Projection\BalanceProjector;
use App\Wallet\Infrastructure\Projection\RebuildProjectionCommand;
use App\Wallet\Infrastructure\Snapshot\DbalSnapshotStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

final class RebuildTest extends KernelTestCase
{
    private Connection $connection;
    private EventSourcedWalletRepository $repository;
    private DbalEventStore $eventStore;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE wallet_balances');
        $this->connection->executeStatement('TRUNCATE TABLE wallet_events');

        $this->eventStore = new DbalEventStore($this->connection, new EventSerializer(new EventTypeRegistry()), new UpcasterChain());
        $this->repository = new EventSourcedWalletRepository(
            $this->eventStore,
            self::getContainer()->get(MessageBusInterface::class),
            new DbalSnapshotStore($this->connection),
        );
    }

    public function test_rebuild_restores_the_projection_from_the_event_store(): void
    {
        $walletId = WalletId::generate();

        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $this->repository->save($wallet);

        $wallet = $this->repository->get($walletId);
        $wallet->hold('hold-1', new Money(40, 'EUR'));
        $this->repository->save($wallet);

        // Corrupt/clear the projection — the live dispatch above already wrote a
        // correct row; we wipe it to prove rebuild reconstructs it independently.
        $this->connection->executeStatement('TRUNCATE TABLE wallet_balances');

        $command = new RebuildProjectionCommand($this->connection, $this->eventStore, new BalanceProjector($this->connection));
        (new CommandTester($command))->execute([]);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM wallet_balances WHERE wallet_id = :walletId',
            ['walletId' => $walletId->toString()],
        );

        self::assertNotFalse($row);
        self::assertSame(100, $row['balance']);
        self::assertSame(40, $row['held']);
        self::assertSame(60, $row['available']);
        self::assertSame(3, (int) $row['last_version']);
    }
}
