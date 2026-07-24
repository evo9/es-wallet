<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Exception;

final class DuplicateHoldException extends \DomainException
{
    public function __construct(string $holdId)
    {
        parent::__construct(sprintf('Hold "%s" already exists.', $holdId));
    }
}
