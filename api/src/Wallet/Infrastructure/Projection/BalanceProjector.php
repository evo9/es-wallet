<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Projection;

use App\Wallet\Domain\Event\FundsHeld;
use App\Wallet\Domain\Event\HoldCaptured;
use App\Wallet\Domain\Event\HoldReleased;
use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\MoneyWithdrawn;
use App\Wallet\Domain\Event\WalletClosed;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\ValueObject\WalletId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Applies only the delta of each event — never reads the aggregate or recomputes
 * business logic. The same __invoke() serves both the live Messenger handler and the
 * rebuild command (task 05 scope item 3): whoever calls it, the code path is identical.
 */
#[AsMessageHandler]
final readonly class BalanceProjector
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(ProjectableEvent $projectable): void
    {
        $event = $projectable->event;
        $version = $projectable->aggregateVersion;

        match ($event::class) {
            WalletOpened::class => $this->onWalletOpened($event, $version),
            MoneyDeposited::class => $this->applyBalanceDelta($event->walletId, $version, $event->amount),
            MoneyWithdrawn::class => $this->applyBalanceDelta($event->walletId, $version, -$event->amount),
            FundsHeld::class => $this->applyHeldDelta($event->walletId, $version, $event->amount),
            HoldReleased::class => $this->onHoldReleased($event, $version),
            HoldCaptured::class => $this->onHoldCaptured($event, $version),
            WalletClosed::class => $this->onWalletClosed($event->walletId, $version),
        };
    }

    private function onWalletOpened(WalletOpened $event, int $version): void
    {
        // Idempotent via ON CONFLICT DO NOTHING: a redelivered WalletOpened must not
        // reset an already-projected wallet back to zero.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO wallet_balances (wallet_id, currency, balance, held, available, closed, last_version, updated_at)
                VALUES (:walletId, :currency, 0, 0, 0, false, :version, now())
                ON CONFLICT (wallet_id) DO NOTHING
                SQL,
            [
                'walletId' => $event->walletId->toString(),
                'currency' => $event->currency,
                'version' => $version,
            ],
        );
    }

    /**
     * WHERE last_version = version - 1 makes redelivery a no-op: a redelivered event's
     * version - 1 no longer matches the already-advanced last_version, so 0 rows update.
     */
    private function applyBalanceDelta(WalletId $walletId, int $version, int $delta): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE wallet_balances
                SET balance = balance + :delta,
                    available = (balance + :delta) - held,
                    last_version = :version,
                    updated_at = now()
                WHERE wallet_id = :walletId AND last_version = :version - 1
                SQL,
            [
                'walletId' => $walletId->toString(),
                'delta' => $delta,
                'version' => $version,
            ],
        );
    }

    private function applyHeldDelta(WalletId $walletId, int $version, int $delta): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE wallet_balances
                SET held = held + :delta,
                    available = balance - (held + :delta),
                    last_version = :version,
                    updated_at = now()
                WHERE wallet_id = :walletId AND last_version = :version - 1
                SQL,
            [
                'walletId' => $walletId->toString(),
                'delta' => $delta,
                'version' => $version,
            ],
        );
    }

    private function onHoldReleased(HoldReleased $event, int $version): void
    {
        // HoldReleased carries only {holdId} (see spec 2.4) — the projector doesn't keep
        // its own hold ledger, so it looks up the matching FundsHeld's amount from the
        // event store. This is a single targeted lookup, not replaying the aggregate.
        $amount = $this->lookupHeldAmount($event->walletId, $event->holdId);

        $this->applyHeldDelta($event->walletId, $version, -$amount);
    }

    private function onHoldCaptured(HoldCaptured $event, int $version): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE wallet_balances
                SET balance = balance - :amount,
                    held = held - :amount,
                    available = (balance - :amount) - (held - :amount),
                    last_version = :version,
                    updated_at = now()
                WHERE wallet_id = :walletId AND last_version = :version - 1
                SQL,
            [
                'walletId' => $event->walletId->toString(),
                'amount' => $event->amount,
                'version' => $version,
            ],
        );
    }

    private function onWalletClosed(WalletId $walletId, int $version): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE wallet_balances
                SET closed = true,
                    last_version = :version,
                    updated_at = now()
                WHERE wallet_id = :walletId AND last_version = :version - 1
                SQL,
            [
                'walletId' => $walletId->toString(),
                'version' => $version,
            ],
        );
    }

    private function lookupHeldAmount(WalletId $walletId, string $holdId): int
    {
        $amount = $this->connection->fetchOne(
            <<<'SQL'
                SELECT payload->>'amount' FROM wallet_events
                WHERE aggregate_id = :walletId AND event_type = 'funds_held' AND payload->>'holdId' = :holdId
                SQL,
            [
                'walletId' => $walletId->toString(),
                'holdId' => $holdId,
            ],
        );

        return (int) $amount;
    }
}
