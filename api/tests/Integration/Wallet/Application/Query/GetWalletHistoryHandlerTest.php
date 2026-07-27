<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application\Query;

use App\Wallet\Application\Query\GetWalletHistory;
use App\Wallet\Application\Query\GetWalletHistoryHandler;
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

final class GetWalletHistoryHandlerTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;
    private GetWalletHistoryHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE wallet_events');
        $connection->executeStatement('TRUNCATE TABLE wallet_balances');

        $eventSerializer = new EventSerializer(new EventTypeRegistry());
        $this->repository = new EventSourcedWalletRepository(
            new DbalEventStore($connection, $eventSerializer, new UpcasterChain()),
            self::getContainer()->get(MessageBusInterface::class),
            new DbalSnapshotStore($connection),
        );
        $this->handler = new GetWalletHistoryHandler(
            new DbalEventStore($connection, $eventSerializer, new UpcasterChain()),
            $eventSerializer,
        );
    }

    public function test_returns_a_human_readable_list_of_events_in_order(): void
    {
        $walletId = WalletId::generate();
        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $this->repository->save($wallet);

        $history = ($this->handler)(new GetWalletHistory($walletId));

        self::assertCount(2, $history);

        self::assertSame('wallet_opened', $history[0]->eventType);
        self::assertSame(['currency' => 'EUR'], $history[0]->payload);

        self::assertSame('money_deposited', $history[1]->eventType);
        self::assertSame(['amount' => 100, 'currency' => 'EUR', 'source' => 'topup'], $history[1]->payload);
    }
}
