<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application\Command;

use App\Wallet\Application\Command\WithdrawMoney;
use App\Wallet\Application\Command\WithdrawMoneyHandler;
use App\Wallet\Application\RetryOnConcurrencyConflict;
use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Infrastructure\EventStore\ConcurrencyException;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use App\Wallet\Infrastructure\Persistence\EventSourcedWalletRepository;
use App\Wallet\Infrastructure\Snapshot\DbalSnapshotStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class WithdrawMoneyHandlerTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;
    private WithdrawMoneyHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE wallet_events');
        $connection->executeStatement('TRUNCATE TABLE wallet_balances');

        $this->repository = new EventSourcedWalletRepository(
            new DbalEventStore($connection, new EventSerializer(new EventTypeRegistry()), new UpcasterChain()),
            self::getContainer()->get(MessageBusInterface::class),
            new DbalSnapshotStore($connection),
        );
        $this->handler = new WithdrawMoneyHandler(
            $this->repository,
            new RetryOnConcurrencyConflict(ConcurrencyException::class),
        );
    }

    public function test_withdrawing_money_decreases_the_balance(): void
    {
        $walletId = WalletId::generate();
        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $this->repository->save($wallet);

        ($this->handler)(new WithdrawMoney($walletId, 40, 'EUR', 'payout'));

        $wallet = $this->repository->get($walletId);
        self::assertSame(60, $wallet->toSnapshotState()['balance']);
    }
}
