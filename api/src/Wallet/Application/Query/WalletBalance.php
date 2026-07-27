<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

/**
 * lastVersion lets the client see how fresh the projection is relative to the event
 * store (spec 4.4) — the projection can lag behind a just-committed write.
 */
final readonly class WalletBalance
{
    public function __construct(
        public string $walletId,
        public string $currency,
        public int $balance,
        public int $held,
        public int $available,
        public bool $closed,
        public int $lastVersion,
    ) {
    }
}
