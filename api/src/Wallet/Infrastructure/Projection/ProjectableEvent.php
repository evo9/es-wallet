<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Projection;

use App\Wallet\Domain\Event\DomainEvent;

/**
 * What gets dispatched to Messenger for projections: pairs a domain event with its
 * position in the aggregate's event stream. The aggregate's stream position is an
 * event-store concern (like a Kafka offset), not something the Domain event itself
 * carries — but the projector needs it for idempotency (last_version), so the
 * Infrastructure layer attaches it here at dispatch time.
 */
final readonly class ProjectableEvent
{
    public function __construct(
        public DomainEvent $event,
        public int $aggregateVersion,
    ) {
    }
}
