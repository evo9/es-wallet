<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application;

use App\Wallet\Application\Query\GetBalance;
use App\Wallet\Application\Query\GetBalanceHandler;
use App\Wallet\Domain\Exception\WalletNotFoundException;
use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use App\Wallet\Infrastructure\Persistence\EventSourcedWalletRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetBalanceHandlerTest extends KernelTestCase
{
    private Connection $connection;
    private EventSourcedWalletRepository $repository;
    private GetBalanceHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE wallet_balances');
        $this->connection->executeStatement('TRUNCATE TABLE wallet_events');

        $this->repository = new EventSourcedWalletRepository(
            new DbalEventStore($this->connection, new EventSerializer(new EventTypeRegistry()), new UpcasterChain()),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $this->handler = new GetBalanceHandler($this->connection);
    }

    public function test_returns_balance_and_last_version_from_the_projection(): void
    {
        $walletId = WalletId::generate();

        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $wallet->hold('hold-1', new Money(40, 'EUR'));
        $this->repository->save($wallet);

        $balance = ($this->handler)(new GetBalance($walletId));

        self::assertSame($walletId->toString(), $balance->walletId);
        self::assertSame('EUR', $balance->currency);
        self::assertSame(100, $balance->balance);
        self::assertSame(40, $balance->held);
        self::assertSame(60, $balance->available);
        self::assertFalse($balance->closed);
        self::assertSame(3, $balance->lastVersion);
    }

    public function test_throws_when_the_wallet_has_no_projection_row(): void
    {
        $this->expectException(WalletNotFoundException::class);

        ($this->handler)(new GetBalance(WalletId::generate()));
    }
}
