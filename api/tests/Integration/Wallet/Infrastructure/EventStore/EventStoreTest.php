<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\EventStore;

use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use App\Wallet\Infrastructure\EventStore\EventSerializer;
use App\Wallet\Infrastructure\EventStore\EventTypeRegistry;
use App\Wallet\Infrastructure\EventStore\Upcaster\UpcasterChain;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventStoreTest extends KernelTestCase
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

    public function test_appended_events_are_loaded_back_in_order_with_correct_payload(): void
    {
        $walletId = WalletId::generate();

        $this->eventStore->append($walletId, 0, [
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
        ]);

        $events = iterator_to_array($this->eventStore->load($walletId));

        self::assertCount(2, $events);

        self::assertInstanceOf(WalletOpened::class, $events[0]);
        self::assertTrue($walletId->equals($events[0]->walletId));
        self::assertSame('EUR', $events[0]->currency);

        self::assertInstanceOf(MoneyDeposited::class, $events[1]);
        self::assertTrue($walletId->equals($events[1]->walletId));
        self::assertSame(100, $events[1]->amount);
        self::assertSame('EUR', $events[1]->currency);
        self::assertSame('topup', $events[1]->source);
    }

    public function test_load_only_returns_events_after_the_given_version(): void
    {
        $walletId = WalletId::generate();

        $this->eventStore->append($walletId, 0, [
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
        ]);

        $events = iterator_to_array($this->eventStore->load($walletId, fromVersion: 1));

        self::assertCount(1, $events);
        self::assertInstanceOf(MoneyDeposited::class, $events[0]);
    }

    public function test_event_type_stored_in_the_database_is_the_logical_name_not_the_fqcn(): void
    {
        $walletId = WalletId::generate();

        $this->eventStore->append($walletId, 0, [new WalletOpened($walletId, 'EUR')]);

        $storedType = $this->connection->fetchOne(
            'SELECT event_type FROM wallet_events WHERE aggregate_id = :aggregateId',
            ['aggregateId' => $walletId->toString()],
        );

        self::assertSame('wallet_opened', $storedType);
    }
}
