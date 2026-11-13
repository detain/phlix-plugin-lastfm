<?php

/**
 * Unit::LastfmSessionRepositoryBindingTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Workerman\MySQL\Connection;

/**
 * Shared black-box recorder behind the PDO fakes below. Every value the
 * vendor {@see Connection} passes to `prepare()`, `bindParam()` and
 * `execute()` is captured here so the test can assert on the full
 * binding path, not just the repository's call site.
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository::class)]
final class LastfmBindingRecorder
{
    /** @var list<string> SQL strings handed to PDO::prepare(), in order. */
    public array $prepareCalls = [];

    /** @var list<array{0: int|string, 1: mixed}> [which, value] per bindParam(). */
    public array $binds = [];

    public int $executeCount = 0;

    /** @var list<array<string, mixed>> Scripted fetchAll() rows. */
    public array $rows = [];

    public int $affectedRows = 1;
}

/**
 * Stand-in for PDOStatement. Deliberately NOT a PDOStatement subclass:
 * the vendor reaches the statement only through the untyped `$sQuery`
 * property, so a duck-typed fake keeps the probe socket-free.
 */
final class LastfmFakePdoStatement
{
    public function __construct(
        private readonly LastfmBindingRecorder $recorder,
        private readonly string $preparedSql,
    ) {
        $this->recorder->prepareCalls[] = $preparedSql;
    }

    /**
     * Mirrors the real signature loosely (the vendor calls it with two
     * positional arguments only). Note the vendor's list-argument path
     * binds 0-based offsets — see the disclosure rider in
     * tests/Unit/.../W-min52 report: a real PDOStatement rejects
     * index 0 with a ValueError, so this fake records what the vendor
     * sends rather than what live MySQL would accept.
     */
    public function bindParam(mixed $which, mixed $value): bool
    {
        $this->recorder->binds[] = [$which, $value];

        return true;
    }

    public function execute(): bool
    {
        $this->recorder->executeCount++;

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(int $mode = 2): array
    {
        return $this->recorder->rows;
    }

    public function fetch(int $mode = 2): mixed
    {
        return false;
    }

    public function rowCount(): int
    {
        return $this->recorder->affectedRows;
    }
}

/** Stand-in for PDO: records prepare() and hands back the scripted statement. */
final class LastfmFakePdo
{
    public function __construct(private readonly LastfmBindingRecorder $recorder)
    {
    }

    public function prepare(string $query): LastfmFakePdoStatement
    {
        return new LastfmFakePdoStatement($this->recorder, $query);
    }

    public function lastInsertId(): string
    {
        return '7';
    }
}

/**
 * Socket-free probe: skips the vendor constructor (which would open a
 * real PDO connection) and overrides `connect()` so the inherited
 * prepare/bind/execute path runs untouched against {@see LastfmFakePdo}.
 * This is the piece that lets the test exercise the REAL vendor binding
 * code rather than a mocked `query()` — the exact gap [review lastfm
 * minors M-10] names.
 */
final class LastfmBindingProbeConnection extends Connection
{
    public function __construct(private readonly LastfmFakePdo $fakePdo)
    {
        // Intentionally no parent::__construct(): the vendor constructor
        // calls connect() against a live MySQL socket. The property
        // initializers still run, and every query flows through the
        // inherited execute()/bindMore() implementation under test.
    }

    protected function connect(): void
    {
        $this->pdo = $this->fakePdo;
    }
}

/**
 * W-min52 M-10 [review lastfm minors W-min52] — pin that the session
 * repository's values reach MySQL as prepared-statement parameters,
 * never as interpolated SQL text, verified against the real
 * workerman/mysql binding path (the sibling suites mock `query()` and
 * therefore cannot see it).
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 1.2.0
 */
final class LastfmSessionRepositoryBindingTest extends TestCase
{
    /**
     * A hostile user id must appear exactly once — in the bind log — and
     * never inside the string handed to PDO::prepare().
     */
    public function testFindByUserIdPreparesPlaceholderSqlAndBindsValueNotInterpolates(): void
    {
        $recorder = new LastfmBindingRecorder();
        $repository = new LastfmSessionRepository(
            new LastfmBindingProbeConnection(new LastfmFakePdo($recorder))
        );
        $hostile = "evil'; DROP TABLE lastfm_sessions; --";
        $recorder->rows = [[
            'user_id'      => $hostile,
            'session_key'  => 'SK',
            'connected_at' => '2026-01-01 00:00:00',
        ]];

        $row = $repository->findByUserId($hostile);

        $this->assertSame([
            'SELECT user_id, session_key, connected_at FROM lastfm_sessions WHERE user_id = ?',
        ], $recorder->prepareCalls, 'the prepared statement must be the constant ?-placeholder SQL');
        $this->assertStringNotContainsString('DROP TABLE', $recorder->prepareCalls[0]);
        $this->assertStringNotContainsString($hostile, $recorder->prepareCalls[0]);
        $this->assertSame(1, substr_count($recorder->prepareCalls[0], '?'));
        // The vendor stores list-argument keys 0-based (bind()/bindMore()
        // verified bytes): recording that raw index is the point — the
        // value travelled through bindParam, not through the SQL text.
        $this->assertSame([[0, $hostile]], $recorder->binds);
        $this->assertSame(1, $recorder->executeCount);
        $this->assertIsArray($row);
        $this->assertSame('SK', $row['session_key']);
    }

    /**
     * save() carries user id, session key and username as three ordered
     * bind parameters; none of them may leak into the prepared text.
     */
    public function testSaveCarriesAllThreeValuesThroughBindParametersNeverSqlText(): void
    {
        $recorder = new LastfmBindingRecorder();
        $repository = new LastfmSessionRepository(
            new LastfmBindingProbeConnection(new LastfmFakePdo($recorder))
        );
        $hostile = "x'; INSERT INTO lastfm_sessions VALUES ('pwned'); --";

        $repository->save('user-1', $hostile, 'alice');

        $this->assertCount(1, $recorder->prepareCalls);
        $sql = $recorder->prepareCalls[0];
        $this->assertStringStartsWith('INSERT INTO lastfm_sessions', $sql);
        $this->assertSame(3, substr_count($sql, '?'), 'exactly three placeholder slots for the three values');
        $this->assertStringNotContainsString($hostile, $sql);
        $this->assertStringNotContainsString('alice', $sql);
        $this->assertSame([[0, 'user-1'], [1, $hostile], [2, 'alice']], $recorder->binds);
        $this->assertSame(1, $recorder->executeCount);
    }

    /** delete() binds the user id rather than concatenating it. */
    public function testDeleteBindsUserIdAgainstPreparedStatement(): void
    {
        $recorder = new LastfmBindingRecorder();
        $repository = new LastfmSessionRepository(
            new LastfmBindingProbeConnection(new LastfmFakePdo($recorder))
        );
        $hostile = "evil' OR '1'='1";

        $repository->delete($hostile);

        $this->assertSame(['DELETE FROM lastfm_sessions WHERE user_id = ?'], $recorder->prepareCalls);
        $this->assertStringNotContainsString($hostile, $recorder->prepareCalls[0]);
        $this->assertSame([[0, $hostile]], $recorder->binds);
        $this->assertSame(1, $recorder->executeCount);
    }

    /**
     * The vendor's own connect() disables emulated prepares, which is
     * what makes `COM_STMT_PREPARE` binding the live contract rather
     * than client-side text substitution. Pinned against the vendored
     * source bytes (composer.lock pins the package, so this is a
     * hermetic file read, not a live-server probe).
     */
    public function testVendorConnectionRunsNativePreparedStatements(): void
    {
        $source = file_get_contents(
            \dirname(__DIR__, 2) . '/vendor/workerman/mysql/src/Connection.php'
        );

        $this->assertIsString($source, 'the vendored workerman/mysql Connection must be present');
        $this->assertStringContainsString(
            'setAttribute(PDO::ATTR_EMULATE_PREPARES, false)',
            $source,
            'native (server-side) prepared statements are the binding contract this suite pins'
        );
    }
}
