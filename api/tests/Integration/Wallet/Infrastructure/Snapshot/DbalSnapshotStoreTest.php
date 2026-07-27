<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\Snapshot;

use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Infrastructure\Snapshot\DbalSnapshotStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalSnapshotStoreTest extends KernelTestCase
{
    private DbalSnapshotStore $store;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE wallet_snapshots');

        $this->store = new DbalSnapshotStore($connection);
    }

    public function test_loading_a_nonexistent_snapshot_returns_null(): void
    {
        self::assertNull($this->store->load(WalletId::generate()));
    }

    public function test_saving_and_loading_a_snapshot_round_trips(): void
    {
        $walletId = WalletId::generate();
        $state = ['walletId' => $walletId->toString(), 'currency' => 'EUR', 'balance' => 100, 'held' => 0, 'holds' => [], 'closed' => false];

        $this->store->save($walletId, 50, $state);

        $loaded = $this->store->load($walletId);

        self::assertSame(50, $loaded['version']);
        self::assertEquals($state, $loaded['state']); // jsonb doesn't preserve key order
    }

    public function test_saving_again_overwrites_the_previous_snapshot(): void
    {
        $walletId = WalletId::generate();
        $this->store->save($walletId, 50, ['balance' => 100]);
        $this->store->save($walletId, 100, ['balance' => 200]);

        $loaded = $this->store->load($walletId);

        self::assertSame(100, $loaded['version']);
        self::assertSame(['balance' => 200], $loaded['state']);
    }
}
