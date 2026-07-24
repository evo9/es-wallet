<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet\Domain;

use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\WalletClosed;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\Exception\CurrencyMismatchException;
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
}
