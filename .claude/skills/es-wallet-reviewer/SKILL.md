---
name: es-wallet-reviewer
description: >
  Professional code review skill for the es-wallet project (PHP 8.3 + Symfony 7 + PostgreSQL,
  Event Sourcing from scratch on Doctrine DBAL — NO ORM, NO ES libraries).
  Use whenever asked to review, check, or validate code: "does this look right?", "check my aggregate",
  "is this correct?", "review what I wrote", "ревью", "проверь код", "посмотри на мой код".
  Understands: ES aggregate pattern (recordThat/apply/reconstitute), DBAL event store with optimistic
  locking via UNIQUE(aggregate_id, version), event serialization by logical name registry, upcasting on
  read, idempotent projections via last_version, snapshots as cache, layer purity (Domain/Application/Infrastructure).
  Produces a structured report with CRITICAL / WARNING / SUGGESTION severity and exact file:line references.
---

# es-wallet — Code Reviewer

You are a senior engineer who knows this codebase inside out. You review code against the
architecture in `CLAUDE.md` and the spec `docs/specs/es-wallet-spec.md`. Cite exact file paths
and line numbers and suggest concrete fixes.

**Always read the actual files before commenting** (Read + Grep). Never review from memory.

## Step 1 — Determine scope

If the user specifies files or a layer, review those. Otherwise infer:

- "review the domain" → `api/src/Wallet/Domain/`
- "review the aggregate" → `api/src/Wallet/Domain/Wallet.php`
- "review the event store" → `api/src/Wallet/Infrastructure/EventStore/`
- "review the repository" → `api/src/Wallet/Infrastructure/Persistence/`
- "review the projection" → `api/src/Wallet/Infrastructure/Projection/`
- "review the application layer" → `api/src/Wallet/Application/`
- "review the API" → `api/src/Wallet/Infrastructure/Http/`
- "review everything" → all of the above

## Step 2 — Run the checklist

---

### 🔴 CRITICAL — Architecture & ES-invariant violations

#### C1. No ORM, no ES libraries
- Grep the whole repo: `doctrine/orm` in `composer.json`, `use Doctrine\ORM`, `#[ORM\` → **forbidden**.
- Any use of Broadway / EventSauce / prooph / other ES-CQRS libraries → **forbidden**.
  The whole point is a from-scratch implementation.

#### C2. Layer purity
- `api/src/Wallet/Domain/` must be pure PHP — grep for and flag:
  - `use Symfony\`, `use Doctrine\` → forbidden in Domain
  - `use App\Wallet\Infrastructure\` or `use App\Wallet\Application\` → forbidden in Domain
- `Application/` must not import `Infrastructure/`:
  - `use App\Wallet\Infrastructure\` inside `Application/` → forbidden (inject Domain interfaces).

#### C3. Aggregate mechanics (canonical ES pattern)
Read `Domain/Wallet.php` and verify:
- Command methods (`deposit`, `withdraw`, `hold`, `releaseHold`, `captureHold`, `close`, `open`)
  **check invariants → build event → `recordThat($event)`**. They must NOT mutate state directly.
- **Only `apply()` mutates state** and increments `version`. Flag any state write outside `apply`.
- `recordThat()` buffers the event as uncommitted AND calls `apply()`.
- `reconstitute(iterable $events)` builds an empty instance (no constructor validation) and replays.
- `pullUncommittedEvents()` returns and clears the buffer.

#### C4. Money & value objects
- `Money` stores `int` minor units + currency string. **Any `float` in money math → forbidden.**
- Cross-currency operations must throw `CurrencyMismatchException`.

#### C5. Event store & optimistic locking
Read `Infrastructure/EventStore/`:
- `append()` inserts one row per event inside a transaction with versions `expectedVersion+1, +2, ...`.
- Concurrency is enforced ONLY by `UNIQUE (aggregate_id, version)`. Flag any `SELECT ... FOR UPDATE`
  or advisory/table locks.
- `UniqueConstraintViolationException` must be caught and rethrown as `ConcurrencyException`.
- `event_type` persisted is the **logical name** (`money_deposited`), NOT a FQCN. Flag FQCN in DB
  or class name resolution via reflection instead of the explicit `EventTypeRegistry`.

#### C6. Invariants coverage (spec 2.2)
Cross-check the aggregate against every row of the invariants table: closed-wallet guard,
currency match, `amount > 0`, `amount <= available` (where `available = balance - held`),
unique holdId, hold existence for release/capture, `held == 0` on close, idempotent repeat `close`.
Flag any missing or wrong invariant / wrong exception type.

---

### 🟡 WARNING — Convention violations

#### W1. Concurrency retry placement
- Retry on `ConcurrencyException` lives in the **application-layer command handler** (1 retry:
  reload aggregate, re-run command, then surface 409). It must NOT live in the repository.

#### W2. Projection idempotency
- `BalanceProjector` updates with `WHERE last_version = :version - 1` (or skips when
  `last_version >= version`). Redelivery must be a no-op. Flag projectors that blindly overwrite.
- The projector applies the event **delta only** — it must not read the aggregate or recompute
  business logic. Live-projection and rebuild must share the same apply code.

#### W3. Query side reads only the read model
- `GetBalanceHandler` reads **only** `wallet_balances`, never the event store. It should also
  return `last_version`. Flag any event-store read on the balance query path.

#### W4. Upcasting on read only
- Upcasters run inside `EventStore.load` between deserialize and event construction.
- The store is never migrated; only the current event class lives in code. Flag any code that
  rewrites stored payloads or keeps obsolete event classes alive beyond the payload form.

#### W5. Snapshot semantics
- Snapshot serializes **state, not events**; it is a cache, not a source of truth. No snapshot-schema
  versioning (invalidate instead). Threshold `N` (default 50) upserts a snapshot after crossing a multiple.

#### W6. Dispatch after commit
- `EventSourcedWalletRepository.save()` dispatches events to Messenger **after** a successful append/commit,
  never before.

#### W7. Test isolation
- Integration tests reset via `TRUNCATE` in `setUp` (not transactional rollback — it breaks the
  unique-constraint check). Messenger transport is `sync` in the test env.

---

### 🔵 SUGGESTION — Quality

- **S1.** Events are immutable (readonly properties), carry `aggregateId`, `occurredAt`, `eventVersion`.
- **S2.** Domain unit tests use the given/when/then `AggregateScenario` with **no mocks**; every
  invariant row from spec 2.2 has a named case.
- **S3.** HTTP controllers are thin: parse input → dispatch command/query → map result. Domain
  exceptions map to 409/404/422 via a single `ExceptionListener`, not inline in controllers.
- **S4.** PHPStan runs at a strict level (aim for max/level 8) if configured.

---

## Step 3 — Write the report

Use this exact structure:

```
## Code Review: <scope reviewed>

### Summary
<2–3 sentences: overall quality, biggest concern, verdict direction>

---

### 🔴 Critical Issues
[None found ✅ — or list issues]

#### `path/to/file.php:42` — Short title
**What the code does:** ...
**Why it's wrong:** ...
**Fix:**
```php
// corrected snippet
```

---

### 🟡 Warnings
[None found ✅ — or list issues]

---

### 🔵 Suggestions
[None found ✅ — or list suggestions]

---

### Verdict
**PASS** / **PASS WITH WARNINGS** / **NEEDS REVISION**

<One sentence on what must change before this is done, or confirmation it's ready>
```

**Verdict rules:**
- `PASS` — zero criticals, zero warnings
- `PASS WITH WARNINGS` — zero criticals, has warnings
- `NEEDS REVISION` — any critical issue present
