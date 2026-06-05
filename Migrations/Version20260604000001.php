<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

final class Version20260604000001 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Add ended_reason column to advanced_maintenance_schedule_history';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE advanced_maintenance_schedule_history ADD COLUMN ended_reason VARCHAR(64) NULL');
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || str_contains(strtolower(get_class($platform)), 'sqlite');

        if ($isSqlite) {
            // SQLite does not support DROP COLUMN in older versions; skip silently.
            $this->warnIf(true, 'SQLite: ended_reason column not dropped (not supported).');
        } else {
            $this->addSql('ALTER TABLE advanced_maintenance_schedule_history DROP COLUMN ended_reason');
        }
    }
}
