# CLAUDE.md — es-wallet

Учебно-портфолийный проект: **Event Sourcing «руками» на PHP/Symfony, без ES-библиотек**.
Домен — кошелёк с балансом и резервированием средств. Полная спека:
`docs/specs/es-wallet-spec.md`. Подзадачи для реализации: `docs/tasks/`.

Цель проекта — не «сделать фичу», а **уметь объяснить каждую строчку** и отвечать на
интервью-вопросы про ES (снапшоты, версионирование событий, ребилд проекций,
идемпотентность, конкурентность) со ссылкой на собственный код. Читаемость и явность
механики важнее краткости.

## Стек

- PHP 8.3+, Symfony 7 (skeleton, минимум бандлов)
- PostgreSQL 16
- **Doctrine DBAL** — event store и проекции пишем на чистом DBAL
- Symfony Messenger — асинхронная доставка событий в проекторы
- PHPUnit — Unit + Integration
- Docker Compose: php-fpm/cli + postgres (+ отдельная тест-БД)

## Жёсткие правила (нарушение = ошибка)

- **ORM запрещён.** Doctrine ORM в проекте не используется вообще. Только DBAL.
  В `composer.json` не должно быть `doctrine/orm`.
- **ES/CQRS-библиотеки запрещены** (Broadway, EventSauce, prooph и любые другие).
  Смысл проекта — показать механику руками. В README одной строкой:
  «in production I would evaluate EventSauce; this is a from-scratch implementation for depth».
- **Money — только `int` в минорных единицах + код валюты (ISO 4217). `float` запрещён.**
- **`event_type` в БД — логическое имя** (`money_deposited`), НЕ FQCN. Маппинг имя⇄класс —
  явный реестр (`EventTypeRegistry`), без рефлексии по классам. FQCN в БД — антипаттерн:
  переименование класса ломает историю.
- **Только `apply()` меняет состояние агрегата.** Командные методы проверяют инварианты и
  пишут событие через `recordThat()`; состояние они не трогают.
- **Конкурентность — только через `UNIQUE (aggregate_id, version)`.** Никаких `SELECT FOR UPDATE`
  и блокировок. Конфликт → `ConcurrencyException`.
- **Ретрай конкурентности — в application-слое** (command handler: 1 повтор → перечитать
  агрегат → повторить команду → иначе 409). НЕ в репозитории.
- **Апкаст только при чтении**; event store не мигрируется. В коде живёт только актуальная
  схема события; старые версии существуют лишь как форма payload в БД.
- **Проектор идемпотентен** через `last_version` (`UPDATE ... WHERE last_version = :version - 1`,
  либо skip). Повторная доставка события — no-op. Не упрощать.
- **Снапшот — кеш, не источник истины.** Сериализует состояние (не события); схему снапшота
  не версионируем — при несовместимости просто удаляем.

## Архитектура и слои

Строгая слоёная зависимость: `Domain ← Application ← Infrastructure`.

- **Domain** (`api/src/Wallet/Domain/`) — чистый PHP, ноль зависимостей от Symfony/Doctrine/
  Infrastructure. Агрегат `Wallet`, события, value objects (`WalletId`, `Money`), исключения,
  интерфейс `WalletRepository`.
- **Application** (`api/src/Wallet/Application/`) — команды и запросы (message + handler,
  Messenger sync bus). Не импортирует Infrastructure.
- **Infrastructure** (`api/src/Wallet/Infrastructure/`) — `DbalEventStore`, сериализатор,
  реестр типов, апкастеры, репозиторий, снапшот-стор, проектор, rebuild-команда,
  HTTP-контроллеры, exception listener.

Namespace: `App\Wallet\...`. Структура каталогов зафиксирована в разделе 10 спеки — следовать ей.

### Поток данных

```
Command → Aggregate → Event Store → Messenger → Projection (read model)
                          ↑                          ↓
                      Snapshot (кеш)          GET /balance читает ТОЛЬКО проекцию
```

Query-сторона (`GetBalance`) читает **только** `wallet_balances`, никогда event store.
`GetWalletHistory` читает event store (аудит).

## Разработка

### Команды (в контейнере php)

```bash
docker compose up -d                                   # поднять php + postgres + тест-БД
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php vendor/bin/phpunit             # все тесты
docker compose exec php vendor/bin/phpunit --testsuite Unit
docker compose exec php vendor/bin/phpunit --testsuite Integration
docker compose exec php bin/console wallet:projection:rebuild
```

> Если в проекте появится `Makefile` (`make test`, `make lint`, `make cs-fix`) — использовать его;
> команды `.claude/commands/*` на него рассчитывают. Пока Makefile нет — вызывать напрямую как выше.

### Тесты

- **Unit** (`api/tests/Unit/`) — домен в стиле given/when/then через `AggregateScenario`,
  **без моков**, без БД. Покрыта каждая строка таблицы инвариантов (спека 2.2).
- **Integration** (`api/tests/Integration/`) — реальный Postgres, отдельная тест-БД.
  Изоляция: `TRUNCATE` таблиц в `setUp` (не транзакционный rollback — он мешает проверке
  unique constraint). Messenger в test env — `sync`.
- TDD: если следуем скиллу `test-driven-development`, домен пишем тест-первым. Обрати внимание:
  разбивка `docs/tasks/` разносит реализацию домена (01) и тесты (02) в отдельные задачи —
  при тест-first подходе выполнять их слитно (тест → минимальный код).

## Порядок реализации (фазы, спека 12)

1. Домен + `AggregateScenario` + `WalletTest` — полностью зелёный, без БД.
2. Event store + сериализатор + конкурентность + репозиторий + ретрай.
3. Проекция + идемпотентность + rebuild + HTTP API.
4. Снапшоты + апкастер + README (если остаётся время до интервью).

Каждая фаза — отдельный коммит/PR, тесты зелёные на каждой фазе.
Детальная разбивка с критериями приёмки — в `docs/tasks/` (см. `docs/tasks/README.md`).

## Явно вне скоупа (не реализовывать)

Аутентификация, мультивалютные конверсии, комиссии; отдельная read-БД/реплики;
сага/process manager; интеграции с внешними системами; Kafka/RabbitMQ (достаточно Messenger
с doctrine transport); фронтенд; API Platform.

## Git

См. `.claude/rules/git-operations.md`. Кратко: не коммитить и не пушить без явного запроса;
в описании PR не упоминать AI-инструменты и не добавлять статистику изменений/чек-листы.
