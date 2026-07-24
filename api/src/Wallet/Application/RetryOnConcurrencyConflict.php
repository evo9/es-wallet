<?php

declare(strict_types=1);

namespace App\Wallet\Application;

/**
 * Reusable retry wrapper for command handlers (see CLAUDE.md: retry policy lives in the
 * application layer, not the repository). Takes the conflict exception as a class-string
 * rather than a `use` import so this file never depends on Infrastructure — the concrete
 * class (App\Wallet\Infrastructure\EventStore\ConcurrencyException) is wired in by
 * whoever constructs this (task 06's service configuration).
 */
final readonly class RetryOnConcurrencyConflict
{
    /**
     * @param class-string<\Throwable> $conflictExceptionClass
     */
    public function __construct(
        private string $conflictExceptionClass,
        private int $maxAttempts = 2,
    ) {
    }

    public function run(callable $operation): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $operation();
            } catch (\Throwable $exception) {
                if (!is_a($exception, $this->conflictExceptionClass) || $attempt >= $this->maxAttempts) {
                    throw $exception;
                }
            }
        }
    }
}
