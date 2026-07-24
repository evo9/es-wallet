<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Exception;

use App\Wallet\Domain\ValueObject\WalletId;

final class WalletNotFoundException extends \DomainException
{
    public function __construct(WalletId $walletId)
    {
        parent::__construct(sprintf('Wallet "%s" not found.', $walletId->toString()));
    }
}
