<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\EventStore;

use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Infrastructure\EventStore\ConcurrencyException;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConcurrencyTest extends KernelTestCase
{
    private Connection $connection;
    private DbalEventStore $eventStore;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE wallet_events');

        $this->eventStore = new DbalEventStore(
            $this->connection,
            new EventSerializer(new EventTypeRegistry()),
            new UpcasterChain(),
        );
    }

    public function test_appending_with_a_stale_expected_version_is_rejected(): void
    {
        $walletId = WalletId::generate();

        $this->eventStore->append($walletId, 0, [new WalletOpened($walletId, 'EUR')]);

        $this->expectException(ConcurrencyException::class);

        $this->eventStore->append($walletId, 0, [new MoneyDeposited($walletId, 100, 'EUR', 'topup')]);
    }
}
