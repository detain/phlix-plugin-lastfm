<?php

/**
 * Database::LastfmMigrationRunner.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Database;

/**
 * Runs database migrations for the Last.fm plugin.
 *
 * Uses `CREATE TABLE IF NOT EXISTS` so calling {@see run()} repeatedly
 * is safe (idempotent). The runner is invoked LAZILY — never at boot:
 * {@see \Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin::onEnable()} does zero
 * I/O, and the migration fires on the FIRST playback event this worker
 * processes, through the deferred schema-ensure closure built by
 * `LastfmPlugin::makeSchemaEnsurer()`. `LastfmBootSafetyTest` pins that
 * `onEnable()` must not run migrations or touch the database.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm\Database
 * @since 0.15.0
 */
final class LastfmMigrationRunner
{
    /**
     * @param \Workerman\MySQL\Connection $db Workerman MySQL connection.
     */
    public function __construct(
        private readonly \Workerman\MySQL\Connection $db,
    ) {
    }

    /**
     * Ensure the plugin schema is present.
     *
     * Applies the one migration this plugin ships —
     * {@see \Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations\CreateLastfmSessionsTable()}
     * — directly. There is no registry, ordering, or versioning layer:
     * a future second migration means extending this method deliberately.
     */
    public function run(): void
    {
        $migration = new \Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations\CreateLastfmSessionsTable();
        $migration->up($this->db);
    }
}
