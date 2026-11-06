<?php

/**
 * Database::Migrations::CreateLastfmSessionsTable.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations;

use Workerman\MySQL\Connection;

/**
 * Create the `lastfm_sessions` table.
 *
 * This table stores per-user Last.fm session keys. Each row ties one Phlix
 * `user_id` to one Last.fm `session_key` and related metadata.
 *
 * `expires_at` is a RESERVED column, deliberately neither read nor written:
 * Last.fm session keys never expire on the API side — the only loss of
 * validity is user revocation, which this plugin handles by DELETING the row
 * (invalid-session self-heal) and the host by replacing it on reconnect.
 * The column exists for cross-repo schema parity with the host's
 * `lastfm_sessions` shape (phlix-server migrations 023/089 create the same
 * column) and its NULL == "does not expire" semantics; dropping it here
 * would fork the mirrored schema for zero benefit.
 *
 * @internal  Called by {@see \Phlix\Plugins\Scrobbler\Lastfm\Database\LastfmMigrationRunner}.
 * @since 0.15.0
 */
final class CreateLastfmSessionsTable implements MigrationInterface
{
    /** @var string Human-readable migration name; the runner dispatches by class and does not consult it. */
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
