<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

use App\Wallet\Domain\Exception\WalletNotFoundException;
use App\Wallet\Domain\ValueObject\WalletId;

interface WalletRepository
{
    /**
     * @throws WalletNotFoundException
     */
    public function get(WalletId $walletId): Wallet;

    public function save(Wallet $wallet): void;
}
