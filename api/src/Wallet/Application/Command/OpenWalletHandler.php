<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Wallet\Domain\Wallet;
use App\Wallet\Domain\WalletRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * No retry here: unlike the other handlers, this doesn't re-read an existing aggregate,
 * so a ConcurrencyException would mean the same walletId was opened twice concurrently —
 * retrying Wallet::open() again would hit the identical conflict, not resolve it.
 */
#[AsMessageHandler]
final readonly class OpenWalletHandler
{
    public function __construct(
        private WalletRepository $repository,
    ) {
    }

    public function __invoke(OpenWallet $command): void
    {
        $this->repository->save(Wallet::open($command->walletId, $command->currency));
    }
}
