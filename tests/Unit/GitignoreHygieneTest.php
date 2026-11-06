<?php

/**
 * Unit::GitignoreHygieneTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;

/**
 * The committed composer.lock IS the audited artifact: the S440 CI gate runs
 * `composer audit --locked` against the tracked lock, so the lock must never
 * sit in .gitignore. An ignore line there is a latent gate trap — a
 * delete-plus-regenerate cycle leaves the new lock untracked and silently
 * excluded from normal add flows, and only the composer-audit job would
 * notice while the test job happily resolves lockless.
 */
final class GitignoreHygieneTest extends TestCase
{
    public function testGitignoreNeverIgnoresTheAuditedComposerLock(): void
    {
        $lines = file(__DIR__ . '/../../.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines, '.gitignore must be readable from the repo root');

        $entries = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $entries[] = $trimmed;
        }

        self::assertStringNotContainsString(
            'composer.lock',
            implode("\n", $entries),
            'the tracked, audited composer.lock must not have an ignore entry',
        );
    }
}
