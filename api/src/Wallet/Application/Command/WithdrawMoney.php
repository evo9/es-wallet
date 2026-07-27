<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Wallet\Domain\ValueObject\WalletId;

final readonly class WithdrawMoney
{
    public function __construct(
        public WalletId $walletId,
        public int $amount,
        public string $currency,
        public string $destination,
    ) {
    }
}
