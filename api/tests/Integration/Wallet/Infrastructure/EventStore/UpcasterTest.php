<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\EventStore;

use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 9.2: a raw v1 payload is written directly (bypassing the serializer, which only
 * ever produces the current schema) — upcasting happens purely on read, the store itself
 * is never migrated.
 */
final class UpcasterTest extends KernelTestCase
{
    private Connection $connection;
    private DbalEventStore $eventStore;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE wallet_events');

        // Fetched from the container so this exercises the real, DI-wired upcaster
        // chain (production config), not a hand-assembled pass-through one.
        $this->eventStore = self::getContainer()->get(DbalEventStore::class);
    }

    private function insertRawEvent(WalletId $walletId, int $version, string $eventType, int $eventVersion, array $payload): void
    {
        $this->connection->insert('wallet_events', [
            'aggregate_id' => $walletId->toString(),
            'version' => $version,
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'payload' => $payload,
            'occurred_at' => new \DateTimeImmutable(),
        ], [
            'payload' => Types::JSON,
            'occurred_at' => Types::DATETIMETZ_IMMUTABLE,
        ]);
    }

    public function test_a_v1_money_deposited_payload_is_upcast_to_v2_with_an_unknown_source(): void
    {
        $walletId = WalletId::generate();
        $this->insertRawEvent($walletId, 1, 'wallet_opened', 1, ['currency' => 'EUR']);
        $this->insertRawEvent($walletId, 2, 'money_deposited', 1, ['amount' => 100, 'currency' => 'EUR']);

        $events = iterator_to_array($this->eventStore->load($walletId));

        self::assertCount(2, $events);
        self::assertInstanceOf(MoneyDeposited::class, $events[1]);
        self::assertSame(100, $events[1]->amount);
        self::assertSame('EUR', $events[1]->currency);
        self::assertSame('unknown', $events[1]->source);
        self::assertSame(2, $events[1]->eventVersion());
    }

    public function test_reconstitute_from_a_history_containing_a_v1_payload_works(): void
    {
        $walletId = WalletId::generate();
        $this->insertRawEvent($walletId, 1, 'wallet_opened', 1, ['currency' => 'EUR']);
        $this->insertRawEvent($walletId, 2, 'money_deposited', 1, ['amount' => 100, 'currency' => 'EUR']);

        $wallet = Wallet::reconstitute($this->eventStore->load($walletId));

        self::assertSame(100, $wallet->toSnapshotState()['balance']);
    }
}
