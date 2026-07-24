<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\EventStore;

use App\Wallet\Domain\Event\DomainEvent;
use App\Wallet\Domain\Event\FundsHeld;
use App\Wallet\Domain\Event\HoldCaptured;
use App\Wallet\Domain\Event\HoldReleased;
use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\MoneyWithdrawn;
use App\Wallet\Domain\Event\WalletClosed;
use App\Wallet\Domain\Event\WalletOpened;

/**
 * Explicit logical-name <-> class map. No reflection over FQCNs: storing a class name
 * in the event store would break history on rename (see CLAUDE.md).
 */
final class EventTypeRegistry
{
    /** @var array<string, class-string<DomainEvent>> */
    private const array TYPE_TO_CLASS = [
        'wallet_opened' => WalletOpened::class,
        'money_deposited' => MoneyDeposited::class,
        'money_withdrawn' => MoneyWithdrawn::class,
        'funds_held' => FundsHeld::class,
        'hold_released' => HoldReleased::class,
        'hold_captured' => HoldCaptured::class,
        'wallet_closed' => WalletClosed::class,
    ];

    public function typeForClass(string $class): string
    {
        $type = array_search($class, self::TYPE_TO_CLASS, true);

        if ($type === false) {
            throw new \InvalidArgumentException(sprintf('No registered event type for class "%s".', $class));
        }

        return $type;
    }

    /**
     * @return class-string<DomainEvent>
     */
    public function classForType(string $type): string
    {
        return self::TYPE_TO_CLASS[$type]
            ?? throw new \InvalidArgumentException(sprintf('No registered class for event type "%s".', $type));
    }
}
