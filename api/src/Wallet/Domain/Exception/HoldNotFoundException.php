<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Exception;

final class HoldNotFoundException extends \DomainException
{
    public function __construct(string $holdId)
    {
        parent::__construct(sprintf('Hold "%s" not found.', $holdId));
    }
}
