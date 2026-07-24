<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Application;

use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;
use App\Wallet\Domain\Wallet;
use App\Wallet\Domain\WalletRepository;

/**
 * Test-only decorator that deterministically simulates a concurrent writer: each get()
 * (or just the first, depending on $always) also commits an unrelated deposit through
 * the wrapped repository, so a caller building on the returned wallet's version collides
 * with a write it didn't know about — without needing real threads/processes.
 */
final class RacingWalletRepository implements WalletRepository
{
    private bool $raceInjected = false;

    public function __construct(
        private readonly WalletRepository $inner,
        private readonly bool $always = false,
    ) {
    }

    public function get(WalletId $walletId): Wallet
    {
        $wallet = $this->inner->get($walletId);

        if ($this->always || !$this->raceInjected) {
            $this->raceInjected = true;

            $racer = $this->inner->get($walletId);
            $racer->deposit(new Money(1, 'EUR'), 'racer');
            $this->inner->save($racer);
        }

        return $wallet;
    }

    public function save(Wallet $wallet): void
    {
        $this->inner->save($wallet);
    }
}
