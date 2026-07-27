<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724163106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wallet_balances table (read model projection)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_balances (
                wallet_id      UUID PRIMARY KEY,
                currency       VARCHAR(3) NOT NULL,
                balance        BIGINT NOT NULL,
                held           BIGINT NOT NULL,
                available      BIGINT NOT NULL,
                closed         BOOLEAN NOT NULL,
                last_version   INT NOT NULL,
                updated_at     TIMESTAMPTZ NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wallet_balances');
    }
}
