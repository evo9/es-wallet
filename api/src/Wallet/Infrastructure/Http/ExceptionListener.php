<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Http;

use App\Wallet\Domain\Exception\ActiveHoldsException;
use App\Wallet\Domain\Exception\CurrencyMismatchException;
use App\Wallet\Domain\Exception\DuplicateHoldException;
use App\Wallet\Domain\Exception\HoldNotFoundException;
use App\Wallet\Domain\Exception\InsufficientFundsException;
use App\Wallet\Domain\Exception\InvalidAmountException;
use App\Wallet\Domain\Exception\WalletClosedException;
use App\Wallet\Domain\Exception\WalletNotFoundException;
use App\Wallet\Infrastructure\EventStore\ConcurrencyException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Single place mapping domain exceptions -> HTTP status (spec section 8). Command/query
 * handlers run through the Messenger bus, which wraps any handler exception in a
 * HandlerFailedException — unwrap that first to reach the actual domain exception.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ExceptionListener
{
    /** @var array<class-string, int> */
    private const array STATUS_BY_EXCEPTION = [
        InsufficientFundsException::class => 409,
        WalletClosedException::class => 409,
        DuplicateHoldException::class => 409,
        ActiveHoldsException::class => 409,
        ConcurrencyException::class => 409,
        WalletNotFoundException::class => 404,
        HoldNotFoundException::class => 404,
        InvalidAmountException::class => 422,
        CurrencyMismatchException::class => 422,
    ];

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof HandlerFailedException) {
            // HandlerFailedException's constructor passes the first handler failure as
            // $previous (getWrappedExceptions() is keyed by handler name, not index 0).
            $exception = $exception->getPrevious() ?? $exception;
        }

        $status = self::STATUS_BY_EXCEPTION[$exception::class] ?? null;

        if ($status === null) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => $exception->getMessage()], $status));
    }
}
