<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application\Command;

use App\Wallet\Application\Command\OpenWallet;
use App\Wallet\Application\Command\OpenWalletHandler;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use App\Wallet\Infrastructure\Persistence\EventSourcedWalletRepository;
use App\Wallet\Infrastructure\Snapshot\DbalSnapshotStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class OpenWalletHandlerTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;
    private OpenWalletHandler $handler;

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
        $this->handler = new OpenWalletHandler($this->repository);
    }

    public function test_opening_a_wallet_persists_it(): void
    {
        $walletId = WalletId::generate();

        ($this->handler)(new OpenWallet($walletId, 'EUR'));

        $wallet = $this->repository->get($walletId);

        self::assertSame([
            'walletId' => $walletId->toString(),
            'currency' => 'EUR',
            'balance' => 0,
            'held' => 0,
            'holds' => [],
            'closed' => false,
        ], $wallet->toSnapshotState());
    }
}
