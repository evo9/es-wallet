# es-wallet

Event Sourcing "by hand" on PHP/Symfony — a wallet with balance and fund holds,
implemented from scratch on Doctrine DBAL (no ORM, no ES/CQRS library).

> in production I would evaluate EventSauce; this is a from-scratch implementation for depth.

## Infrastructure

- Docker Compose: `php` (PHP 8.3 CLI, pdo_pgsql + intl) and `postgres` (PostgreSQL 16).
- A single Postgres instance hosts two databases — `es_wallet` (main) and `es_wallet_test`
  (integration tests) — the second one created by `docker/postgres/init-test-db.sh` on
  first container start. Simpler than a second Postgres container; isolation between
  main and test data comes from the database boundary, not a separate server.
- `DATABASE_URL` and `MESSENGER_TRANSPORT_DSN` are set as real environment variables in
  `compose.yaml` rather than in `api/.env` — Symfony's Dotenv always lets a real
  environment variable win over anything loaded from `.env*` files, so this is where the
  actual (committed, reproducible) connection strings live. Test-database isolation is
  handled by `config/packages/doctrine.yaml`'s `when@test: dbal: dbname_suffix`, which
  appends `_test` to the database name resolved from `DATABASE_URL` only when
  `APP_ENV=test` — so one `DATABASE_URL` covers both databases.

Full architecture, decisions and usage instructions land in a later task (docs/tasks/07).

## Running locally

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php bin/console list
docker compose exec php vendor/bin/phpunit
```
