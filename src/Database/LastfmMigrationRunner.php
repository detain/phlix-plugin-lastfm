<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Database;

/**
 * Runs database migrations for the Last.fm plugin.
 *
 * Uses `CREATE TABLE IF NOT EXISTS` so calling {@see run()} repeatedly
 * is safe (idempotent). The runner is invoked by {@see LastfmPlugin::onEnable()}
 * before the session repository is resolved from the container.
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
     * Execute all pending migrations.
     *
     * Currently runs a single migration; the design supports adding subsequent
     * numbered migrations that will be run in order.
     */
    public function run(): void
    {
        $migration = new \Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations\CreateLastfmSessionsTable();
        $migration->up($this->db);
    }
}
