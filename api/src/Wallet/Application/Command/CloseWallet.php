<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Wallet\Domain\ValueObject\WalletId;

final readonly class CloseWallet
{
    public function __construct(
        public WalletId $walletId,
    ) {
    }
}
