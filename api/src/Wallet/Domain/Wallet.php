<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

use App\Wallet\Domain\Event\DomainEvent;
use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\WalletClosed;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\Exception\CurrencyMismatchException;
use App\Wallet\Domain\Exception\InvalidAmountException;
use App\Wallet\Domain\Exception\WalletClosedException;
use App\Wallet\Domain\ValueObject\Money;
use App\Wallet\Domain\ValueObject\WalletId;

final class Wallet
{
    private WalletId $walletId;
    private string $currency;
    private int $balance = 0;
    private int $held = 0;

    /** @var array<string, int> */
    private array $holds = [];
    private bool $closed = false;
    private int $version = 0;

    /** @var DomainEvent[] */
    private array $uncommittedEvents = [];

    private function __construct()
    {
    }

    public static function open(WalletId $walletId, string $currency): self
    {
        $wallet = new self();
        $wallet->recordThat(new WalletOpened($walletId, $currency));

        return $wallet;
    }

    /**
     * @param iterable<DomainEvent> $events
     */
    public static function reconstitute(iterable $events): self
    {
        $wallet = new self();
        foreach ($events as $event) {
            $wallet->apply($event);
        }

        return $wallet;
    }

    public function deposit(Money $money, string $source): void
    {
        if ($this->closed) {
            throw new WalletClosedException($this->walletId);
        }

        if ($money->currency() !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $money->currency());
        }

        if (!$money->isPositive()) {
            throw new InvalidAmountException($money->amount());
        }

        $this->recordThat(new MoneyDeposited($this->walletId, $money->amount(), $money->currency(), $source));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->recordThat(new WalletClosed($this->walletId));
    }

    /**
     * @return DomainEvent[]
     */
    public function pullUncommittedEvents(): array
    {
        $events = $this->uncommittedEvents;
        $this->uncommittedEvents = [];

        return $events;
    }

    private function recordThat(DomainEvent $event): void
    {
        $this->uncommittedEvents[] = $event;
        $this->apply($event);
    }

    private function apply(DomainEvent $event): void
    {
        match ($event::class) {
            WalletOpened::class => $this->applyWalletOpened($event),
            MoneyDeposited::class => $this->applyMoneyDeposited($event),
            WalletClosed::class => $this->applyWalletClosed($event),
        };

        $this->version++;
    }

    private function applyWalletOpened(WalletOpened $event): void
    {
        $this->walletId = $event->walletId;
        $this->currency = $event->currency;
    }

    private function applyMoneyDeposited(MoneyDeposited $event): void
    {
        $this->balance += $event->amount;
    }

    private function applyWalletClosed(WalletClosed $event): void
    {
        $this->closed = true;
    }
}
