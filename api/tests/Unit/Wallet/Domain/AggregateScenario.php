<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet\Domain;

use App\Wallet\Domain\Event\DomainEvent;
use App\Wallet\Domain\Wallet;
use PHPUnit\Framework\Assert;

/**
 * given(events...)->when(fn (Wallet $w) => ...)->then(events...) | ->thenThrows(Exception::class)
 */
final class AggregateScenario
{
    private ?\Throwable $caughtException = null;

    private function __construct(
        private Wallet $wallet,
    ) {
    }

    public static function given(DomainEvent ...$events): self
    {
        return new self(Wallet::reconstitute($events));
    }

    /**
     * Static factories (e.g. Wallet::open()) return a new Wallet instance instead of
     * mutating the one passed in — if the action returns one, it replaces the scenario's
     * aggregate so events recorded during its construction are visible to then().
     */
    public function when(callable $action): self
    {
        try {
            $result = $action($this->wallet);
            if ($result instanceof Wallet) {
                $this->wallet = $result;
            }
        } catch (\Throwable $exception) {
            $this->caughtException = $exception;
        }

        return $this;
    }

    /**
     * Returns self so a scenario can chain further when()/then() steps on the same
     * aggregate (e.g. to observe how one command's effects constrain the next).
     */
    public function then(DomainEvent ...$expectedEvents): self
    {
        if ($this->caughtException !== null) {
            throw $this->caughtException;
        }

        $actualEvents = $this->wallet->pullUncommittedEvents();

        Assert::assertCount(
            count($expectedEvents),
            $actualEvents,
            'Unexpected number of recorded events.',
        );

        foreach ($expectedEvents as $index => $expectedEvent) {
            $actualEvent = $actualEvents[$index];

            Assert::assertSame($expectedEvent::class, $actualEvent::class);
            Assert::assertEquals(
                $this->comparablePayload($expectedEvent),
                $this->comparablePayload($actualEvent),
            );
        }

        return $this;
    }

    public function thenThrows(string $exceptionClass): void
    {
        Assert::assertInstanceOf($exceptionClass, $this->caughtException);
    }

    /**
     * Asserts the aggregate's serialized state (see Wallet::toSnapshotState()) rather than
     * its recorded events — for scenarios about resulting state, not what was emitted.
     */
    public function thenState(array $expectedState): self
    {
        if ($this->caughtException !== null) {
            throw $this->caughtException;
        }

        Assert::assertSame($expectedState, $this->wallet->toSnapshotState());

        return $this;
    }

    private function comparablePayload(DomainEvent $event): array
    {
        $payload = get_object_vars($event);
        unset($payload['occurredAt']);

        return $payload;
    }
}
