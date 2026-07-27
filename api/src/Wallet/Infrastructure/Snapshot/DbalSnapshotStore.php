<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Snapshot;

use App\Wallet\Domain\ValueObject\WalletId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * A cache, not the source of truth: serializes Wallet::toSnapshotState() (state, not
 * events); the schema of $state is never versioned — if it becomes incompatible with the
 * current Wallet shape, the caller drops the row and replays from the event store
 * (see EventSourcedWalletRepository::get() and README).
 */
final readonly class DbalSnapshotStore
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{state: array<string, mixed>, version: int}|null
     */
    public function load(WalletId $walletId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT state, version FROM wallet_snapshots WHERE aggregate_id = :walletId',
            ['walletId' => $walletId->toString()],
        );

        if ($row === false) {
            return null;
        }

        return [
            'state' => json_decode((string) $row['state'], true, flags: JSON_THROW_ON_ERROR),
            'version' => (int) $row['version'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(WalletId $walletId, int $version, array $state): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO wallet_snapshots (aggregate_id, version, state, created_at)
                VALUES (:walletId, :version, :state, now())
                ON CONFLICT (aggregate_id) DO UPDATE
                    SET version = :version, state = :state, created_at = now()
                SQL,
            [
                'walletId' => $walletId->toString(),
                'version' => $version,
                'state' => $state,
            ],
            [
                'state' => Types::JSON,
            ],
        );
    }

    public function delete(WalletId $walletId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM wallet_snapshots WHERE aggregate_id = :walletId',
            ['walletId' => $walletId->toString()],
        );
    }
}
