<?php

/**
 * Unit::LastfmSessionRepositoryTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see LastfmSessionRepository}.
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository::class)]
#[AllowMockObjectsWithoutExpectations]
final class LastfmSessionRepositoryTest extends TestCase
{
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject */
    private Connection $db;

    private LastfmSessionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(Connection::class);
        $this->repo = new LastfmSessionRepository($this->db);
    }

    public function testFindByUserIdReturnsHydratedRow(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('SELECT user_id, session_key'), ['user-1'])
            ->willReturn([
                ['user_id' => 'user-1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00'],
            ]);

        $row = $this->repo->findByUserId('user-1');
        $this->assertNotNull($row);
        $this->assertSame('user-1', $row['user_id']);
        $this->assertSame('SK', $row['session_key']);
        $this->assertSame('2024-01-01 00:00:00', $row['connected_at']);
    }

    public function testFindByUserIdReturnsNullWhenMissing(): void
    {
        $this->db->method('query')->willReturn([]);
        $this->assertNull($this->repo->findByUserId('nope'));
    }

    public function testFindByUserIdReturnsNullWhenColumnsMistyped(): void
    {
        $this->db->method('query')->willReturn([['user_id' => 1, 'session_key' => null, 'connected_at' => null]]);
        $this->assertNull($this->repo->findByUserId('user-1'));
    }

    public function testSaveIssuesUpsert(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('ON DUPLICATE KEY UPDATE'),
                ['user-1', 'SK', ''],
            )
            ->willReturn([]);
        $this->repo->save('user-1', 'SK');
    }

    public function testSaveWithUsernameIssuesUpsert(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('ON DUPLICATE KEY UPDATE'),
                ['user-1', 'SK', 'rj'],
            )
            ->willReturn([]);
        $this->repo->save('user-1', 'SK', 'rj');
    }

    public function testSaveUsesRowAliasUpsertNotDeprecatedValuesFunction(): void
    {
        // VALUES() inside ON DUPLICATE KEY UPDATE is deprecated since MySQL
        // 8.0.20; the row-alias form (8.0.19+) is the forward-compatible
        // spelling of the same upsert [review lastfm__srphp M-18].
        $captured = null;
        $this->db->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $args) use (&$captured): array {
                $captured = [$sql, $args];
                return [];
            });

        $this->repo->save('user-1', 'SK', 'rj');

        $this->assertIsArray($captured);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[0]);
        $this->assertStringContainsString('AS new', $captured[0]);
        $this->assertStringNotContainsString('= VALUES(', $captured[0]);
        $this->assertStringContainsString('session_key = new.session_key', $captured[0]);
        $this->assertStringContainsString('username    = new.username', $captured[0]);
        $this->assertStringContainsString('connected_at = new.connected_at', $captured[0]);
        $this->assertSame(['user-1', 'SK', 'rj'], $captured[1]);
    }

    public function testDeleteIssuesDelete(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with('DELETE FROM lastfm_sessions WHERE user_id = ?', ['user-1'])
            ->willReturn([]);
        $this->repo->delete('user-1');
    }
}
