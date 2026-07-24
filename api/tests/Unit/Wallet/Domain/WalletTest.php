<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet\Domain;

use App\Wallet\Domain\Event\FundsHeld;
use App\Wallet\Domain\Event\HoldCaptured;
use App\Wallet\Domain\Event\HoldReleased;
use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\MoneyWithdrawn;
use App\Wallet\Domain\Event\WalletClosed;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\Exception\ActiveHoldsException;
use App\Wallet\Domain\Exception\CurrencyMismatchException;
use App\Wallet\Domain\Exception\DuplicateHoldException;
use App\Wallet\Domain\Exception\HoldNotFoundException;
use App\Wallet\Domain\Exception\InsufficientFundsException;
use App\Wallet\Domain\Exception\InvalidAmountException;
use App\Wallet\Domain\Exception\WalletClosedException;
use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use PHPUnit\Framework\TestCase;

final class WalletTest extends TestCase
{
    public function test_opening_a_wallet_records_wallet_opened(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given()
            ->when(fn (Wallet $wallet) => Wallet::open($walletId, 'EUR'))
            ->then(new WalletOpened($walletId, 'EUR'));
    }

    public function test_depositing_money_records_money_deposited(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->deposit(new Money(100, 'EUR'), 'topup'))
            ->then(new MoneyDeposited($walletId, 100, 'EUR', 'topup'));
    }

    public function test_closing_a_wallet_records_wallet_closed(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->close())
            ->then(new WalletClosed($walletId));
    }

    public function test_closing_an_already_closed_wallet_is_idempotent(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new WalletClosed($walletId))
            ->when(fn (Wallet $wallet) => $wallet->close())
            ->then();
    }

    public function test_depositing_into_a_closed_wallet_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new WalletClosed($walletId))
            ->when(fn (Wallet $wallet) => $wallet->deposit(new Money(100, 'EUR'), 'topup'))
            ->thenThrows(WalletClosedException::class);
    }

    public function test_depositing_money_in_a_different_currency_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->deposit(new Money(100, 'USD'), 'topup'))
            ->thenThrows(CurrencyMismatchException::class);
    }

    public function test_depositing_a_non_positive_amount_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->deposit(new Money(0, 'EUR'), 'topup'))
            ->thenThrows(InvalidAmountException::class);
    }

    public function test_withdrawing_money_records_money_withdrawn(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new MoneyDeposited($walletId, 100, 'EUR', 'topup'))
            ->when(fn (Wallet $wallet) => $wallet->withdraw(new Money(40, 'EUR'), 'payout'))
            ->then(new MoneyWithdrawn($walletId, 40, 'EUR', 'payout'));
    }

    public function test_withdrawing_from_a_closed_wallet_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new WalletClosed($walletId))
            ->when(fn (Wallet $wallet) => $wallet->withdraw(new Money(40, 'EUR'), 'payout'))
            ->thenThrows(WalletClosedException::class);
    }

    public function test_withdrawing_money_in_a_different_currency_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->withdraw(new Money(40, 'USD'), 'payout'))
            ->thenThrows(CurrencyMismatchException::class);
    }

    public function test_withdrawing_a_non_positive_amount_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->withdraw(new Money(0, 'EUR'), 'payout'))
            ->thenThrows(InvalidAmountException::class);
    }

    public function test_withdrawing_more_than_available_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new MoneyDeposited($walletId, 100, 'EUR', 'topup'))
            ->when(fn (Wallet $wallet) => $wallet->withdraw(new Money(150, 'EUR'), 'payout'))
            ->thenThrows(InsufficientFundsException::class);
    }

    public function test_holding_funds_records_funds_held(): void
    {
        $walletId = WalletId::generate();
        $holdId = 'hold-1';

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new MoneyDeposited($walletId, 100, 'EUR', 'topup'))
            ->when(fn (Wallet $wallet) => $wallet->hold($holdId, new Money(60, 'EUR')))
            ->then(new FundsHeld($walletId, $holdId, 60, 'EUR'));
    }

    public function test_holding_funds_in_a_closed_wallet_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new WalletClosed($walletId))
            ->when(fn (Wallet $wallet) => $wallet->hold('hold-1', new Money(60, 'EUR')))
            ->thenThrows(WalletClosedException::class);
    }

    public function test_holding_funds_in_a_different_currency_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->hold('hold-1', new Money(60, 'USD')))
            ->thenThrows(CurrencyMismatchException::class);
    }

    public function test_holding_a_non_positive_amount_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->hold('hold-1', new Money(0, 'EUR')))
            ->thenThrows(InvalidAmountException::class);
    }

    public function test_holding_more_than_available_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'), new MoneyDeposited($walletId, 100, 'EUR', 'topup'))
            ->when(fn (Wallet $wallet) => $wallet->hold('hold-1', new Money(150, 'EUR')))
            ->thenThrows(InsufficientFundsException::class);
    }

    public function test_holding_funds_with_a_duplicate_hold_id_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 30, 'EUR'),
        )
            ->when(fn (Wallet $wallet) => $wallet->hold('hold-1', new Money(10, 'EUR')))
            ->thenThrows(DuplicateHoldException::class);
    }

    public function test_releasing_a_hold_records_hold_released(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 60, 'EUR'),
        )
            ->when(fn (Wallet $wallet) => $wallet->releaseHold('hold-1'))
            ->then(new HoldReleased($walletId, 'hold-1'));
    }

    public function test_releasing_a_hold_in_a_closed_wallet_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 60, 'EUR'),
            new HoldReleased($walletId, 'hold-1'),
            new WalletClosed($walletId),
        )
            ->when(fn (Wallet $wallet) => $wallet->releaseHold('hold-1'))
            ->thenThrows(WalletClosedException::class);
    }

    public function test_releasing_a_nonexistent_hold_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->releaseHold('unknown-hold'))
            ->thenThrows(HoldNotFoundException::class);
    }

    public function test_capturing_a_hold_records_hold_captured(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 60, 'EUR'),
        )
            ->when(fn (Wallet $wallet) => $wallet->captureHold('hold-1'))
            ->then(new HoldCaptured($walletId, 'hold-1', 60));
    }

    public function test_capturing_a_hold_in_a_closed_wallet_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 60, 'EUR'),
            new HoldCaptured($walletId, 'hold-1', 60),
            new WalletClosed($walletId),
        )
            ->when(fn (Wallet $wallet) => $wallet->captureHold('hold-1'))
            ->thenThrows(WalletClosedException::class);
    }

    public function test_capturing_a_nonexistent_hold_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(new WalletOpened($walletId, 'EUR'))
            ->when(fn (Wallet $wallet) => $wallet->captureHold('unknown-hold'))
            ->thenThrows(HoldNotFoundException::class);
    }

    public function test_closing_a_wallet_with_an_active_hold_is_rejected(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 60, 'EUR'),
        )
            ->when(fn (Wallet $wallet) => $wallet->close())
            ->thenThrows(ActiveHoldsException::class);
    }

    public function test_available_balance_accounts_for_held_funds(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 60, 'EUR'),
        )
            ->when(fn (Wallet $wallet) => $wallet->withdraw(new Money(50, 'EUR'), 'payout'))
            ->thenThrows(InsufficientFundsException::class);
    }

    public function test_capturing_a_hold_decreases_both_balance_and_held(): void
    {
        $walletId = WalletId::generate();

        // If held were not decreased, available (balance - held) would go negative and
        // the withdrawal below would be rejected; if balance were not decreased, it would
        // exceed what is actually left. Only correct bookkeeping of both lets it succeed.
        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 60, 'EUR'),
        )
            ->when(fn (Wallet $wallet) => $wallet->captureHold('hold-1'))
            ->then(new HoldCaptured($walletId, 'hold-1', 60))
            ->when(fn (Wallet $wallet) => $wallet->withdraw(new Money(40, 'EUR'), 'payout'))
            ->then(new MoneyWithdrawn($walletId, 40, 'EUR', 'payout'));
    }

    public function test_reconstituting_from_a_full_mixed_history_yields_correct_state(): void
    {
        $walletId = WalletId::generate();

        AggregateScenario::given(
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 200, 'EUR', 'topup'),
            new FundsHeld($walletId, 'hold-1', 50, 'EUR'),
            new HoldCaptured($walletId, 'hold-1', 50),
            new MoneyDeposited($walletId, 30, 'EUR', 'topup'),
        )
            ->thenState([
                'walletId' => $walletId->toString(),
                'currency' => 'EUR',
                'balance' => 180,
                'held' => 0,
                'holds' => [],
                'closed' => false,
            ]);
    }

    public function test_wallet_restored_from_a_snapshot_behaves_like_one_restored_from_history(): void
    {
        $walletId = WalletId::generate();

        $fromHistory = Wallet::reconstitute([
            new WalletOpened($walletId, 'EUR'),
            new MoneyDeposited($walletId, 100, 'EUR', 'topup'),
        ]);

        $fromSnapshot = Wallet::fromSnapshot($fromHistory->toSnapshotState(), 2);

        $fromHistory->deposit(new Money(50, 'EUR'), 'topup');
        $fromSnapshot->deposit(new Money(50, 'EUR'), 'topup');

        self::assertSame($fromHistory->toSnapshotState(), $fromSnapshot->toSnapshotState());
    }
}
