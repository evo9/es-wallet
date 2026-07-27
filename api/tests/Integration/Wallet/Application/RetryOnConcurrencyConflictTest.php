<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application;

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

final class RetryOnConcurrencyConflictTest extends KernelTestCase
{
    private EventSourcedWalletRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE wallet_events');

        $this->repository = new EventSourcedWalletRepository(
            new DbalEventStore($connection, new EventSerializer(new EventTypeRegistry()), new UpcasterChain()),
            self::getContainer()->get(MessageBusInterface::class),
            new DbalSnapshotStore($connection),
        );
    }

    public function test_a_concurrency_conflict_is_retried_once_and_then_succeeds(): void
    {
        $walletId = WalletId::generate();
        $this->repository->save(Wallet::open($walletId, 'EUR'));

        $racingRepository = new RacingWalletRepository($this->repository);
        $retry = new RetryOnConcurrencyConflict(ConcurrencyException::class);

        $retry->run(function () use ($racingRepository, $walletId) {
            $wallet = $racingRepository->get($walletId);
            $wallet->deposit(new Money(50, 'EUR'), 'topup');
            $racingRepository->save($wallet);
        });

        $final = $this->repository->get($walletId);

        // 1 (racer, injected during the first attempt's get()) + 50 (our own deposit,
        // committed only on the retried attempt) = 51.
        self::assertSame(51, $final->toSnapshotState()['balance']);
    }

    public function test_exhausting_retries_rethrows_the_conflict(): void
    {
        $walletId = WalletId::generate();
        $this->repository->save(Wallet::open($walletId, 'EUR'));

        $racingRepository = new RacingWalletRepository($this->repository, always: true);
        $retry = new RetryOnConcurrencyConflict(ConcurrencyException::class);

        $this->expectException(ConcurrencyException::class);

        $retry->run(function () use ($racingRepository, $walletId) {
            $wallet = $racingRepository->get($walletId);
            $wallet->deposit(new Money(5, 'EUR'), 'topup');
            $racingRepository->save($wallet);
        });
    }
}
