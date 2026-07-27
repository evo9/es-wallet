<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\ValueObject\WalletId;

final readonly class GetBalance
{
    public function __construct(
        public WalletId $walletId,
    ) {
    }
}
