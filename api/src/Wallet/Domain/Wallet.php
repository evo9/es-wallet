<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

use App\Wallet\Domain\Event\DomainEvent;
use App\Wallet\Domain\Event\FundsHeld;
use App\Wallet\Domain\Event\HoldCaptured;
use App\Wallet\Domain\Event\HoldReleased;
use App\Wallet\Domain\Event\MoneyDeposited;
use App\Wallet\Domain\Event\MoneyWithdrawn;
use App\Wallet\Domain\Event\WalletClosed;
use App\Wallet\Domain\Event\WalletOpened;
use App\Wallet\Domain\Exception\ActiveHoldsException;
use App\Wallet\Domain\Exception\CurrencyMismatchException;
use App\Wallet\Domain\Exception\DuplicateHoldException;
use App\Wallet\Domain\Exception\HoldNotFoundException;
use App\Wallet\Domain\Exception\InsufficientFundsException;
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

    /**
     * @param array{walletId: string, currency: string, balance: int, held: int, holds: array<string, int>, closed: bool} $state
     */
    public static function fromSnapshot(array $state, int $version): self
    {
        // `?? null` on every field (rather than direct access) so a missing key raises a
        // clean TypeError from the typed-property assignment below instead of a PHP
        // warning — callers (the repository) treat an incompatible shape as one error.
        $wallet = new self();
        $wallet->walletId = WalletId::fromString($state['walletId'] ?? null);
        $wallet->currency = $state['currency'] ?? null;
        $wallet->balance = $state['balance'] ?? null;
        $wallet->held = $state['held'] ?? null;
        $wallet->holds = $state['holds'] ?? null;
        $wallet->closed = $state['closed'] ?? null;
        $wallet->version = $version;

        return $wallet;
    }

    /**
     * Applies already-committed events (e.g. the tail after a snapshot) directly via
     * apply() — unlike recordThat(), these are NOT new events, so they must not land in
     * the uncommitted buffer or be re-persisted/re-dispatched by the repository.
     *
     * @param iterable<DomainEvent> $events
     */
    public function applyHistory(iterable $events): void
    {
        foreach ($events as $event) {
            $this->apply($event);
        }
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

    public function withdraw(Money $money, string $destination): void
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

        if ($money->amount() > $this->available()) {
            throw new InsufficientFundsException($money->amount(), $this->available());
        }

        $this->recordThat(new MoneyWithdrawn($this->walletId, $money->amount(), $money->currency(), $destination));
    }

    public function hold(string $holdId, Money $money): void
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

        if ($money->amount() > $this->available()) {
            throw new InsufficientFundsException($money->amount(), $this->available());
        }

        if (array_key_exists($holdId, $this->holds)) {
            throw new DuplicateHoldException($holdId);
        }

        $this->recordThat(new FundsHeld($this->walletId, $holdId, $money->amount(), $money->currency()));
    }

    public function releaseHold(string $holdId): void
    {
        if ($this->closed) {
            throw new WalletClosedException($this->walletId);
        }

        if (!array_key_exists($holdId, $this->holds)) {
            throw new HoldNotFoundException($holdId);
        }

        $this->recordThat(new HoldReleased($this->walletId, $holdId));
    }

    public function captureHold(string $holdId): void
    {
        if ($this->closed) {
            throw new WalletClosedException($this->walletId);
        }

        if (!array_key_exists($holdId, $this->holds)) {
            throw new HoldNotFoundException($holdId);
        }

        $amount = $this->holds[$holdId];

        $this->recordThat(new HoldCaptured($this->walletId, $holdId, $amount));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->held !== 0) {
            throw new ActiveHoldsException($this->walletId);
        }

        $this->recordThat(new WalletClosed($this->walletId));
    }

    /**
     * Serializes state (not events) for the snapshot cache — see spec 6. The aggregate
     * version is tracked separately by the snapshot store, not part of this payload.
     */
    /**
     * Total number of events applied so far (history + uncommitted) — the repository
     * uses this to compute expectedVersion for append() (see CLAUDE.md concurrency rule).
     */
    public function version(): int
    {
        return $this->version;
    }

    public function toSnapshotState(): array
    {
        return [
            'walletId' => $this->walletId->toString(),
            'currency' => $this->currency,
            'balance' => $this->balance,
            'held' => $this->held,
            'holds' => $this->holds,
            'closed' => $this->closed,
        ];
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

    private function available(): int
    {
        return $this->balance - $this->held;
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
            MoneyWithdrawn::class => $this->applyMoneyWithdrawn($event),
            FundsHeld::class => $this->applyFundsHeld($event),
            HoldReleased::class => $this->applyHoldReleased($event),
            HoldCaptured::class => $this->applyHoldCaptured($event),
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

    private function applyMoneyWithdrawn(MoneyWithdrawn $event): void
    {
        $this->balance -= $event->amount;
    }

    private function applyFundsHeld(FundsHeld $event): void
    {
        $this->held += $event->amount;
        $this->holds[$event->holdId] = $event->amount;
    }

    private function applyHoldReleased(HoldReleased $event): void
    {
        $this->held -= $this->holds[$event->holdId];
        unset($this->holds[$event->holdId]);
    }

    private function applyHoldCaptured(HoldCaptured $event): void
    {
        $this->balance -= $event->amount;
        $this->held -= $event->amount;
        unset($this->holds[$event->holdId]);
    }

    private function applyWalletClosed(WalletClosed $event): void
    {
        $this->closed = true;
    }
}
