<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Wallet\Application\RetryOnConcurrencyConflict;
use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\WalletRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DepositMoneyHandler
{
    public function __construct(
        private WalletRepository $repository,
        private RetryOnConcurrencyConflict $retry,
    ) {
    }

    public function __invoke(DepositMoney $command): void
    {
        $this->retry->run(function () use ($command) {
            $wallet = $this->repository->get($command->walletId);
            $wallet->deposit(new Money($command->amount, $command->currency), $command->source);
            $this->repository->save($wallet);
        });
    }
}
