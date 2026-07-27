<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\Projection;

use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\MoneyWithdrawn;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use App\Wallet\Infrastructure\Persistence\EventSourcedWalletRepository;
use App\Wallet\Infrastructure\Projection\BalanceProjector;
use App\Wallet\Infrastructure\Projection\ProjectableEvent;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProjectionTest extends KernelTestCase
{
    private Connection $connection;
    private BalanceProjector $projector;
    private EventSourcedWalletRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE wallet_balances');
        $this->connection->executeStatement('TRUNCATE TABLE wallet_events');

        $this->projector = new BalanceProjector($this->connection);
        $this->repository = new EventSourcedWalletRepository(
            new DbalEventStore($this->connection, new EventSerializer(new EventTypeRegistry()), new UpcasterChain()),
            self::getContainer()->get(MessageBusInterface::class),
        );
    }

    private function fetchBalance(WalletId $walletId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM wallet_balances WHERE wallet_id = :walletId',
            ['walletId' => $walletId->toString()],
        );

        self::assertNotFalse($row, 'Expected a wallet_balances row to exist.');

        return $row;
    }

    public function test_wallet_opened_creates_a_balance_row(): void
    {
        $walletId = WalletId::generate();

        ($this->projector)(new ProjectableEvent(new WalletOpened($walletId, 'EUR'), 1));

        $row = $this->fetchBalance($walletId);

        self::assertSame('EUR', $row['currency']);
        self::assertSame(0, $row['balance']);
        self::assertSame(0, $row['held']);
        self::assertSame(0, $row['available']);
        self::assertFalse((bool) $row['closed']);
        self::assertSame(1, (int) $row['last_version']);
    }

    public function test_saving_a_wallet_through_the_repository_projects_it_via_messenger(): void
    {
        $walletId = WalletId::generate();

        $this->repository->save(Wallet::open($walletId, 'EUR'));

        $row = $this->fetchBalance($walletId);

        self::assertSame(1, (int) $row['last_version']);
    }

    public function test_deposit_and_withdraw_adjust_balance(): void
    {
        $walletId = WalletId::generate();

        ($this->projector)(new ProjectableEvent(new WalletOpened($walletId, 'EUR'), 1));
        ($this->projector)(new ProjectableEvent(new MoneyDeposited($walletId, 100, 'EUR', 'topup'), 2));
        ($this->projector)(new ProjectableEvent(new MoneyWithdrawn($walletId, 30, 'EUR', 'payout'), 3));

        $row = $this->fetchBalance($walletId);

        self::assertSame(70, $row['balance']);
        self::assertSame(0, $row['held']);
        self::assertSame(70, $row['available']);
        self::assertSame(3, (int) $row['last_version']);
    }

    public function test_hold_and_capture_adjust_held_and_balance(): void
    {
        $walletId = WalletId::generate();

        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $this->repository->save($wallet);

        $wallet = $this->repository->get($walletId);
        $wallet->hold('hold-1', new Money(60, 'EUR'));
        $this->repository->save($wallet);

        $row = $this->fetchBalance($walletId);
        self::assertSame(100, $row['balance']);
        self::assertSame(60, $row['held']);
        self::assertSame(40, $row['available']);

        $wallet = $this->repository->get($walletId);
        $wallet->captureHold('hold-1');
        $this->repository->save($wallet);

        $row = $this->fetchBalance($walletId);
        self::assertSame(40, $row['balance']);
        self::assertSame(0, $row['held']);
        self::assertSame(40, $row['available']);
    }

    public function test_releasing_a_hold_restores_available_balance(): void
    {
        $walletId = WalletId::generate();

        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $this->repository->save($wallet);

        $wallet = $this->repository->get($walletId);
        $wallet->hold('hold-1', new Money(60, 'EUR'));
        $this->repository->save($wallet);

        $wallet = $this->repository->get($walletId);
        $wallet->releaseHold('hold-1');
        $this->repository->save($wallet);

        $row = $this->fetchBalance($walletId);
        self::assertSame(100, $row['balance']);
        self::assertSame(0, $row['held']);
        self::assertSame(100, $row['available']);
    }

    public function test_closing_a_wallet_marks_it_closed_in_the_projection(): void
    {
        $walletId = WalletId::generate();

        $wallet = Wallet::open($walletId, 'EUR');
        $this->repository->save($wallet);

        $wallet = $this->repository->get($walletId);
        $wallet->close();
        $this->repository->save($wallet);

        $row = $this->fetchBalance($walletId);
        self::assertTrue((bool) $row['closed']);
    }

    public function test_redelivering_the_same_event_does_not_change_the_state(): void
    {
        $walletId = WalletId::generate();

        ($this->projector)(new ProjectableEvent(new WalletOpened($walletId, 'EUR'), 1));

        $deposited = new ProjectableEvent(new MoneyDeposited($walletId, 100, 'EUR', 'topup'), 2);
        ($this->projector)($deposited);
        ($this->projector)($deposited); // redelivery: must be a no-op

        $row = $this->fetchBalance($walletId);
        self::assertSame(100, $row['balance']);
        self::assertSame(2, (int) $row['last_version']);
    }

    public function test_redelivering_wallet_opened_does_not_reset_the_balance(): void
    {
        $walletId = WalletId::generate();
        $opened = new ProjectableEvent(new WalletOpened($walletId, 'EUR'), 1);

        ($this->projector)($opened);
        ($this->projector)(new ProjectableEvent(new MoneyDeposited($walletId, 100, 'EUR', 'topup'), 2));
        ($this->projector)($opened); // redelivery: must not wipe the deposit back to 0

        $row = $this->fetchBalance($walletId);
        self::assertSame(100, $row['balance']);
        self::assertSame(2, (int) $row['last_version']);
    }
}
