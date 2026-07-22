<?php

/**
 * Unit::LastfmMigrationRunnerTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\Database\LastfmMigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Consequence tests for {@see LastfmMigrationRunner}.
 *
 * The deferred schema-ensure may run on the first event in each of ~14
 * workers, so the migration MUST be idempotent: it uses
 * `CREATE TABLE IF NOT EXISTS`, and calling run() twice is safe.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\Database\LastfmMigrationRunner
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\Database\Migrations\CreateLastfmSessionsTable
 */
final class LastfmMigrationRunnerTest extends TestCase
{
    public function testMigrationIsIdempotentCreateTableIfNotExists(): void
    {
        $queries = [];
        $conn = $this->createMock(\Workerman\MySQL\Connection::class);
        $conn->method('query')->willReturnCallback(function (string $sql) use (&$queries) {
            $queries[] = $sql;
            return [];
        });

        $runner = new LastfmMigrationRunner($conn);

        // Safe to call twice — the second call must not throw.
        $runner->run();
        $runner->run();

        self::assertCount(2, $queries, 'run() should issue its migration each call');
        foreach ($queries as $sql) {
            self::assertMatchesRegularExpression(
                '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`lastfm_sessions`/i',
                $sql,
                'migration must be idempotent (CREATE TABLE IF NOT EXISTS)',
            );
        }
    }
}
