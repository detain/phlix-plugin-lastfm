<?php

/**
 * Unit::LastfmSessionRepositoryEdgeCasesTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Workerman\MySQL\Connection;

/**
 * Edge case tests for {@see LastfmSessionRepository}.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmSessionRepositoryEdgeCasesTest extends TestCase
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

    /**
     * Test that findByUserId returns null when the first row is not an array.
     * This covers the defensive check at line 54 in LastfmSessionRepository.
     */
    public function testFindByUserIdReturnsNullWhenRowIsNotAnArray(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->willReturn([null]); // First element is null, not an array

        $result = $this->repo->findByUserId('user-1');

        $this->assertNull($result);
    }

    /**
     * Test that findByUserId returns null when query returns non-array.
     */
    public function testFindByUserIdReturnsNullWhenQueryReturnsNonArray(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->willReturn(false); // Query failed or returned false

        $result = $this->repo->findByUserId('user-1');

        $this->assertNull($result);
    }

    /**
     * Test that findByUserId returns null when session_key is empty string.
     */
    public function testFindByUserIdReturnsNullWhenSessionKeyEmpty(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->willReturn([
                ['user_id' => 'user-1', 'session_key' => '', 'connected_at' => '2024-01-01 00:00:00'],
            ]);

        $result = $this->repo->findByUserId('user-1');

        $this->assertNull($result);
    }

    /**
     * Test that findByUserId returns null when username is empty string but session_key is valid.
     * Actually, looking at the code, username is not validated - only user_id, session_key, and connected_at.
     * Let me verify this is the case and write appropriate tests.
     */
    public function testFindByUserIdReturnsSessionWhenUsernameIsEmpty(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->willReturn([
                ['user_id' => 'user-1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00'],
            ]);

        $result = $this->repo->findByUserId('user-1');

        $this->assertNotNull($result);
        $this->assertSame('user-1', $result['user_id']);
        $this->assertSame('SK', $result['session_key']);
        // username is not in the SELECT, so it won't be in the result
        $this->assertArrayNotHasKey('username', $result);
    }

    /**
     * Test that delete issues the correct query with userId parameter.
     */
    public function testDeleteCallsQueryWithCorrectUserId(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(
                'DELETE FROM lastfm_sessions WHERE user_id = ?',
                ['test-user-id']
            )
            ->willReturn([]);

        $this->repo->delete('test-user-id');
    }

    /**
     * Test save with default empty username.
     */
    public function testSaveWithDefaultEmptyUsername(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO lastfm_sessions'),
                ['user-1', 'SK', ''] // username defaults to ''
            )
            ->willReturn([]);

        $this->repo->save('user-1', 'SK');
    }
}
