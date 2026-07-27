<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application\Command;

use App\Wallet\Application\Command\CaptureHold;
use App\Wallet\Application\Command\CaptureHoldHandler;
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

final class CaptureHoldHandlerTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;
    private CaptureHoldHandler $handler;

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
        $this->handler = new CaptureHoldHandler(
            $this->repository,
            new RetryOnConcurrencyConflict(ConcurrencyException::class),
        );
    }

    public function test_capturing_a_hold_reduces_balance_and_held(): void
    {
        $walletId = WalletId::generate();
        $wallet = Wallet::open($walletId, 'EUR');
        $wallet->deposit(new Money(100, 'EUR'), 'topup');
        $wallet->hold('hold-1', new Money(40, 'EUR'));
        $this->repository->save($wallet);

        ($this->handler)(new CaptureHold($walletId, 'hold-1'));

        $wallet = $this->repository->get($walletId);
        $state = $wallet->toSnapshotState();
        self::assertSame(60, $state['balance']);
        self::assertSame(0, $state['held']);
    }
}
