<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application\Command;

use App\Wallet\Application\Command\ReleaseHold;
use App\Wallet\Application\Command\ReleaseHoldHandler;
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

final class ReleaseHoldHandlerTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;
    private ReleaseHoldHandler $handler;

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
        $this->handler = new ReleaseHoldHandler(
            $this->repository,
            new RetryOnConcurrencyConflict(ConcurrencyException::class),
        );
    }

    public function test_releasing_a_hold_frees_the_reserved_amount(): void
    {
        $walletId = WalletId::generate();
        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $wallet->hold('hold-1', new Money(40, 'EUR'));
        $this->repository->save($wallet);

        ($this->handler)(new ReleaseHold($walletId, 'hold-1'));

        $wallet = $this->repository->get($walletId);
        self::assertSame(0, $wallet->toSnapshotState()['held']);
    }
}
