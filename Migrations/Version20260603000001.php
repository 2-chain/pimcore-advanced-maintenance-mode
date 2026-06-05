<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

final class Version20260603000001 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Create advanced_maintenance_schedule_history table';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || str_contains(strtolower(get_class($platform)), 'sqlite');

        if ($isSqlite) {
            $this->addSql(<<<'SQL'
                CREATE TABLE IF NOT EXISTS advanced_maintenance_schedule_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    schedule_window_id VARCHAR(36) NOT NULL,
                    started_at DATETIME NOT NULL,
                    ended_at DATETIME NULL,
                    duration_minutes INT NULL,
                    configured_duration_minutes INT NULL,
                    type VARCHAR(16) NULL,
                    reason VARCHAR(500) NULL,
                    scope_path_prefixes TEXT NULL,
                    scope_site_ids TEXT NULL
                )
                SQL);
        } else {
            $this->addSql(<<<'SQL'
                CREATE TABLE IF NOT EXISTS advanced_maintenance_schedule_history (
                    id INT NOT NULL AUTO_INCREMENT,
                    schedule_window_id VARCHAR(36) NOT NULL,
                    started_at DATETIME NOT NULL,
                    ended_at DATETIME NULL,
                    duration_minutes INT NULL,
                    configured_duration_minutes INT NULL,
                    type VARCHAR(16) NULL,
                    reason VARCHAR(500) NULL,
                    scope_path_prefixes TEXT NULL,
                    scope_site_ids TEXT NULL,
                    PRIMARY KEY (id),
                    INDEX idx_schedule_window_id (schedule_window_id),
                    INDEX idx_started_at (started_at)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
        }
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS advanced_maintenance_schedule_history');
    }
}
