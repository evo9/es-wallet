<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724154509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wallet_events table (event store)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_events (
                id            BIGSERIAL PRIMARY KEY,
                aggregate_id  UUID        NOT NULL,
                version       INT         NOT NULL,
                event_type    VARCHAR(64) NOT NULL,
                event_version INT         NOT NULL DEFAULT 1,
                payload       JSONB       NOT NULL,
                occurred_at   TIMESTAMPTZ NOT NULL,
                UNIQUE (aggregate_id, version)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wallet_events');
    }
}
