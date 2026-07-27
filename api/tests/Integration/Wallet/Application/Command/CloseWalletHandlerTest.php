<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application\Command;

use App\Wallet\Application\Command\CloseWallet;
use App\Wallet\Application\Command\CloseWalletHandler;
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

final class CloseWalletHandlerTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;
    private CloseWalletHandler $handler;

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
        $this->handler = new CloseWalletHandler(
            $this->repository,
            new RetryOnConcurrencyConflict(ConcurrencyException::class),
        );
    }

    public function test_closing_a_wallet_marks_it_closed(): void
    {
        $walletId = WalletId::generate();
        $this->repository->save(Wallet::open($walletId, 'EUR'));

        ($this->handler)(new CloseWallet($walletId));

        $wallet = $this->repository->get($walletId);
        self::assertTrue($wallet->toSnapshotState()['closed']);
    }
}
