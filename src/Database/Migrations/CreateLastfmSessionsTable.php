<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations;

use Workerman\MySQL\Connection;

/**
 * Create the `lastfm_sessions` table.
 *
 * This table stores per-user Last.fm session keys. Each row ties one Phlix
 * `user_id` to one Last.fm `session_key` and related metadata.
 *
 * @internal  Called by {@see \Phlix\Plugins\Scrobbler\Lastfm\Database\LastfmMigrationRunner}.
 * @since 0.15.0
 */
final class CreateLastfmSessionsTable implements MigrationInterface
{
    /** @var string Human-readable migration name used by the runner. */
    public const NAME = 'create_lastfm_sessions';

    /**
     * Run the migration using the provided Workerman MySQL connection.
     *
     * @param Connection $db Workerman MySQL connection.
     */
    public function up(Connection $db): void
    {
        $db->query("
            CREATE TABLE IF NOT EXISTS `lastfm_sessions` (
                `user_id`       VARCHAR(36)  NOT NULL COMMENT 'Phlix user UUID',
                `session_key`   VARCHAR(64)  NOT NULL COMMENT 'Last.fm session key from auth.getSession',
                `username`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Last.fm username (for display)',
                `connected_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the user authorised access',
                `expires_at`    DATETIME     NULL COMMENT 'When the session expires (null = does not expire)',
                PRIMARY KEY (`user_id`),
                KEY `idx_lastfm_sessions_username` (`username`),
                KEY `idx_lastfm_sessions_connected_at` (`connected_at`)
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
        ");
    }
}
