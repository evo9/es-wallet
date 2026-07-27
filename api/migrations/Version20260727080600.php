<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727080600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wallet_snapshots table (aggregate load-time cache)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_snapshots (
                aggregate_id UUID PRIMARY KEY,
                version      INT NOT NULL,
                state        JSONB NOT NULL,
                created_at   TIMESTAMPTZ NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wallet_snapshots');
    }
}
