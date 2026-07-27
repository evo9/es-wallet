# es-wallet

Event Sourcing "by hand" on PHP/Symfony — a wallet with balance and fund holds,
implemented from scratch on Doctrine DBAL (no ORM, no ES/CQRS library).

> in production I would evaluate EventSauce; this is a from-scratch implementation for depth.

## What this is, and why

This is a learning/portfolio project, not a product. The goal isn't "a wallet API" — it's
being able to explain every line of an Event Sourcing implementation: how an aggregate
records and replays events, how optimistic concurrency works without locks, how a read
model stays consistent under at-least-once delivery, how event schemas evolve without
migrating history, and how snapshots trade storage for load-time speed. Every one of
those is a common ES interview question, and every one has working, tested code behind
it in this repo.

## Flow

```
Command → Aggregate → Event Store → Messenger → Projection (read model)
                          ↑                          ↓
                      Snapshot (cache)        GET /balance reads ONLY the projection
```

1. An HTTP request reaches `WalletController`, which only parses input and dispatches a
   command or query on the Messenger bus — no business logic in the controller.
2. A command handler loads the `Wallet` aggregate (`WalletRepository::get()`), calls a
   command method on it, and saves it back (`WalletRepository::save()`).
3. The aggregate's command methods check invariants and record events; `save()` appends
   those events to `wallet_events` under an optimistic-concurrency check.
4. After a successful commit, each event is dispatched again — this time wrapped with its
   aggregate version — to `BalanceProjector`, which applies just that event's delta to
   `wallet_balances`.
5. Queries (`GetBalance`, `GetWalletHistory`) never touch the aggregate: balance reads
   come from the projection, history reads come straight from the event store.

## Key decisions

**`UNIQUE (aggregate_id, version)` instead of locks.** Concurrency control is a single
unique index on the event store, not `SELECT ... FOR UPDATE`. `append()` writes each new
event at `expectedVersion + 1, + 2, ...`; if another writer got there first, the insert
collides with the constraint and Postgres rejects it — turned into a `ConcurrencyException`
by the event store. The retry itself is an application-layer concern
(`RetryOnConcurrencyConflict`): a command handler re-reads the aggregate and re-applies the
command once before giving up, matching the natural "read-modify-write" shape of a command
handler far better than a lock ever could, and it costs nothing when there's no contention.

**Upcasting happens only on read.** The store is never migrated. `MoneyDeposited` v1 (no
`source` field) and v2 (with it) can both exist in `wallet_events` at the same time; only
the v2 class exists in code. When `DbalEventStore::load()` reads a v1 row, `UpcasterChain`
rewrites its payload (`source` defaults to `'unknown'`) before the event object is built.
Nothing about the domain, the aggregate, or the rest of the read path needs to know v1 ever
existed — the aggregate always sees the current schema.

**A snapshot is a cache, not the source of truth.** `wallet_snapshots` stores a serialized
`Wallet` *state* (balance, held, holds, closed — via `toSnapshotState()`), never events, and
its schema is deliberately **not versioned**. If the `Wallet` class ever changes shape in a
way that makes an old snapshot row incompatible, `EventSourcedWalletRepository::get()` just
catches the resulting error, deletes that one row, and falls back to a full replay from the
event store — which is still the actual source of truth. There is no snapshot migration
command because there's nothing to migrate: worst case, one aggregate loads slightly slower
until it re-crosses the snapshot threshold (every 50 events) and a fresh, compatible
snapshot gets written. This trade-off — versioning events but never snapshots — is one of
the more common Event Sourcing interview questions, and this is why the answer is "don't."

**The projector is idempotent via `last_version`, not deduplication.** Messenger delivers
at-least-once, so `BalanceProjector` has to tolerate the same event arriving twice. Every
update is `UPDATE wallet_balances SET ... , last_version = :version WHERE last_version =
:version - 1` (or an `INSERT ... ON CONFLICT DO NOTHING` for `WalletOpened`); a redelivered
event's `:version - 1` no longer matches the already-advanced `last_version`, so the second
delivery touches zero rows. The exact same `BalanceProjector::__invoke()` also powers
`wallet:projection:rebuild` — replaying the whole event store through it is just delivering
every event once more, which the idempotency check already handles correctly by construction.

## Layering

```
Domain  ←  Application  ←  Infrastructure
```

`Domain` (`src/Wallet/Domain/`) is plain PHP with zero dependencies on Symfony, Doctrine,
or Infrastructure — the aggregate, its events, value objects, exceptions, and the
`WalletRepository` port. `Application` depends only on `Domain` and Messenger's own
attributes/interfaces, never on `App\Wallet\Infrastructure\*` — with two documented,
narrow exceptions: `GetBalanceHandler` and `GetWalletHistoryHandler` read directly via
Doctrine DBAL / the event store, because a read-only query has no write-side invariant to
protect behind a port that would exist only to wrap a single implementation.
`RetryOnConcurrencyConflict` takes the concurrency exception as a `class-string` rather
than importing it, so the one place that actually names
`Infrastructure\EventStore\ConcurrencyException` is the DI wiring in `services.yaml`, not
Application code. `Infrastructure` implements the ports and holds everything
framework/database-specific: the DBAL event store, the projector, the repository, the
snapshot store, and the HTTP layer.

## Running locally

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate
```

- Docker Compose: `php` (PHP 8.3 CLI, pdo_pgsql + intl) and `postgres` (PostgreSQL 16).
- A single Postgres instance hosts two databases — `es_wallet` (main) and `es_wallet_test`
  (integration tests) — the second one created by `docker/postgres/init-test-db.sh` on
  first container start.
- `DATABASE_URL` and `MESSENGER_TRANSPORT_DSN` are set as real environment variables in
  `compose.yaml` rather than in `api/.env` — Symfony's Dotenv always lets a real
  environment variable win over anything loaded from `.env*` files, so this is where the
  actual (committed, reproducible) connection strings live. Test-database isolation is
  handled by `config/packages/doctrine.yaml`'s `when@test: dbal: dbname_suffix`, which
  appends `_test` to the database name resolved from `DATABASE_URL` only when
  `APP_ENV=test` — so one `DATABASE_URL` covers both databases.

### API walkthrough

```bash
# Open a wallet
curl -s -X POST localhost:8080/wallets -H 'Content-Type: application/json' \
  -d '{"currency":"EUR"}'
# -> 201 {"walletId":"..."}

WALLET_ID=... # from the response above

curl -s -X POST localhost:8080/wallets/$WALLET_ID/deposit -H 'Content-Type: application/json' \
  -d '{"amount":10000,"currency":"EUR","source":"topup"}'
# -> 202

curl -s -X POST localhost:8080/wallets/$WALLET_ID/holds -H 'Content-Type: application/json' \
  -d '{"holdId":"hold-1","amount":4000,"currency":"EUR"}'
# -> 202

curl -s localhost:8080/wallets/$WALLET_ID/balance
# -> 200 {"balance":10000,"held":4000,"available":6000,"lastVersion":3,...}

curl -s localhost:8080/wallets/$WALLET_ID/history
# -> 200 [{"eventType":"wallet_opened",...}, {"eventType":"money_deposited",...}, ...]
```

(A local web server / Caddy/nginx config for `public/index.php` isn't included — run
through Symfony's built-in server, e.g. `docker compose exec php php -S 0.0.0.0:8080 -t
public`, or your own front controller of choice.)

### Rebuilding the projection

```bash
docker compose exec php bin/console wallet:projection:rebuild
```

Truncates `wallet_balances` and replays every event in `wallet_events`, in the order they
were originally committed, through the same `BalanceProjector` the live Messenger handler
uses. Safe to run at any time — the read model is always fully derivable from the event
store.

## Tests

```bash
docker compose exec php vendor/bin/phpunit                # everything
docker compose exec php vendor/bin/phpunit --testsuite Unit         # domain only, no DB
docker compose exec php vendor/bin/phpunit --testsuite Integration  # real Postgres
```

- **Unit** (`api/tests/Unit/`) — the domain, given/when/then style via `AggregateScenario`,
  no mocks, no database. Every row of the invariant table (spec §2.2) and every
  cross-cutting scenario (spec §9.1) is its own named test.
- **Integration** (`api/tests/Integration/`) — real Postgres against the `es_wallet_test`
  database. Isolation is `TRUNCATE` in each test's `setUp()`, not a transactional
  rollback — a rollback would swallow the `UNIQUE (aggregate_id, version)` violation that
  `ConcurrencyTest` exists to check.
- Messenger runs on the `sync` transport in the test environment
  (`config/packages/test/messenger.yaml`), so a dispatched event is fully projected before
  `dispatch()` returns — no polling or waiting needed in tests.
