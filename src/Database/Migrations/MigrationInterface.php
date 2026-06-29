<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations;

/**
 * Minimum interface that all Last.fm plugin migrations must implement.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations
 * @since 0.15.0
 */
interface MigrationInterface
{
    /**
     * Apply the migration using the given Workerman MySQL connection.
     *
     * @param \Workerman\MySQL\Connection $db
     */
    public function up(\Workerman\MySQL\Connection $db): void;
}
