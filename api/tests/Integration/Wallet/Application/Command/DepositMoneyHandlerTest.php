<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application\Command;

use App\Wallet\Application\Command\DepositMoney;
use App\Wallet\Application\Command\DepositMoneyHandler;
use App\Wallet\Application\RetryOnConcurrencyConflict;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Infrastructure\EventStore\ConcurrencyException;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use App\Wallet\Infrastructure\Persistence\EventSourcedWalletRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DepositMoneyHandlerTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;
    private DepositMoneyHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE wallet_events');
        $connection->executeStatement('TRUNCATE TABLE wallet_balances');

        $this->repository = new EventSourcedWalletRepository(
            new DbalEventStore($connection, new EventSerializer(new EventTypeRegistry()), new UpcasterChain()),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $this->handler = new DepositMoneyHandler(
            $this->repository,
            new RetryOnConcurrencyConflict(ConcurrencyException::class),
        );
    }

    public function test_depositing_money_increases_the_balance(): void
    {
        $walletId = WalletId::generate();
        $this->repository->save(Wallet::open($walletId, 'EUR'));

        ($this->handler)(new DepositMoney($walletId, 100, 'EUR', 'topup'));

        $wallet = $this->repository->get($walletId);
        self::assertSame(100, $wallet->toSnapshotState()['balance']);
    }
}
