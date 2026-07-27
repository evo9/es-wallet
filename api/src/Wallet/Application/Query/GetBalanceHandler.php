<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\Exception\WalletNotFoundException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads ONLY wallet_balances — never the event store (spec 4.4). Depends on
 * Doctrine\DBAL\Connection directly rather than an App\Wallet\Infrastructure\* class:
 * the "Application doesn't import Infrastructure" rule (CLAUDE.md) is about not coupling
 * to our own adapters (DbalEventStore, repositories, ...) so write-side logic stays
 * swappable; a read-only query against a plain SQL projection has no domain invariants
 * to protect behind a port, so going straight to DBAL avoids a interface that would
 * exist only to be implemented once.
 */
#[AsMessageHandler]
final readonly class GetBalanceHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(GetBalance $query): WalletBalance
    {
        $row = $this->connection->fetchAssociative(
            'SELECT wallet_id, currency, balance, held, available, closed, last_version FROM wallet_balances WHERE wallet_id = :walletId',
            ['walletId' => $query->walletId->toString()],
        );

        if ($row === false) {
            throw new WalletNotFoundException($query->walletId);
        }

        return new WalletBalance(
            $row['wallet_id'],
            $row['currency'],
            (int) $row['balance'],
            (int) $row['held'],
            (int) $row['available'],
            (bool) $row['closed'],
            (int) $row['last_version'],
        );
    }
}
