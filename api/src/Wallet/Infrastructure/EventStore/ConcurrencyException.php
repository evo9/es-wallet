<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore;

use App\Wallet\Domain\ValueObject\WalletId;

/**
 * Domain-neutral: raised when the UNIQUE (aggregate_id, version) constraint is violated.
 * Retry policy lives in the application layer, not here (see CLAUDE.md).
 */
final class ConcurrencyException extends \RuntimeException
{
    public function __construct(WalletId $aggregateId, int $expectedVersion, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Concurrent write conflict for wallet "%s" at expected version %d.',
                $aggregateId->toString(),
                $expectedVersion,
            ),
            previous: $previous,
        );
    }
}
