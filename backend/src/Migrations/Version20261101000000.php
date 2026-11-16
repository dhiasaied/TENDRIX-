<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Session versioning: every JWT carries the user's session version; bumping it revokes all tokens.
 */
final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.session_version for server-side session revocation.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration targets PostgreSQL.');
        $this->addSql('ALTER TABLE "user" ADD session_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP session_version');
    }
}
