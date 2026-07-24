---
description: Diagnose and fix a failing GitHub Actions CI run
---

You are diagnosing and fixing a CI failure in this repository.

## Input

The user provided: `$ARGUMENTS`
(PR number, run ID, or branch name — if empty, check the current branch)

## Step 1: Find the failing run

```bash
gh run list --branch $(git rev-parse --abbrev-ref HEAD) --limit 5
gh pr checks $ARGUMENTS
```

## Step 2: Get failure details

```bash
gh run view <run-id> --json jobs --jq '.jobs[] | select(.conclusion=="failure") | {name, steps: [.steps[] | select(.conclusion=="failure") | .name]}'
gh run view <run-id> --log-failed
```

## Step 3: Identify failure type

Map the failing job to one of:

- **phpstan** — static analysis failure
- **cs-fixer** — code style violation
- **phpunit-unit** — domain unit test failure (no DB)
- **phpunit-integration** — integration test failure (real Postgres)

## Step 4: Fix

For **phpstan / cs-fixer**:
```bash
# Prefer the Makefile if present (make lint / make cs-fix); otherwise:
docker compose exec php vendor/bin/phpstan analyse
docker compose exec php vendor/bin/php-cs-fixer fix        # auto-fix code style
```

For **PHPUnit** — find the root cause in production code, fix the code (not the test unless the test is wrong):
```bash
docker compose exec php vendor/bin/phpunit
docker compose exec php vendor/bin/phpunit --testsuite Unit
docker compose exec php vendor/bin/phpunit --testsuite Integration
docker compose exec php vendor/bin/phpunit tests/path/to/TestCase.php
```

Integration failures: check the test DB is up (docker compose) and migrations are applied.

## Step 5: Verify locally

```bash
docker compose exec php vendor/bin/phpstan analyse
docker compose exec php vendor/bin/phpunit
```

## Step 6: Report to user

- Root cause (one sentence)
- Files changed
- Local verification result
- Ask user if they want to commit and push
