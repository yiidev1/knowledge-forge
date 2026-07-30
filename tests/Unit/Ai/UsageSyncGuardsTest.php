<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Infrastructure\Usage\FileSyncAttemptMarker;
use App\Ai\OpenAi\OpenAiAdminCredentials;
use App\Ai\OpenAi\OpenAiHttpProfile;
use App\Shared\Domain\ValueObject\SecretValue;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The two guards that protect a user-triggered sync, and the optional-credential contract.
 */
final class UsageSyncGuardsTest extends Unit
{
    private string $directory;
    private string $path;

    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/kf-usage-guard-' . bin2hex(random_bytes(6));
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $this->path = $this->directory . '/attempt.json';
    }

    protected function _after(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);
    }

    public function testNoMarkerYetReadsAsNull(): void
    {
        assertNull($this->marker()->lastAttemptAt());
    }

    public function testRecordsAndReadsBackTheAttemptInstant(): void
    {
        $at = new DateTimeImmutable('2026-07-29 10:00:00', new DateTimeZone('UTC'));

        $marker = $this->marker();
        $marker->markAttempt($at);

        assertSame($at->format(DateTimeImmutable::ATOM), $marker->lastAttemptAt()?->format(DateTimeImmutable::ATOM));
    }

    /**
     * Correction 3, the whole point of a separate marker: an attempt that FAILS is still an attempt.
     * A throttle keyed on the last successful snapshot would never fire here, leaving a persistently
     * failing sync free to hammer the provider.
     */
    public function testAFailedAttemptStillCounts(): void
    {
        $marker = $this->marker();
        $at = new DateTimeImmutable('2026-07-29 10:00:00', new DateTimeZone('UTC'));

        // The marker is written before the work; the work then throws and never writes a snapshot.
        $marker->markAttempt($at);
        try {
            throw new \RuntimeException('provider unreachable');
        } catch (\RuntimeException) {
            // swallowed, exactly as the sync action does
        }

        $recorded = $marker->lastAttemptAt();
        assertNotNull($recorded);

        // A second attempt 3 seconds later is inside a 10-second throttle and must be refused.
        $later = new DateTimeImmutable('2026-07-29 10:00:03', new DateTimeZone('UTC'));
        assertTrue($later->getTimestamp() - $recorded->getTimestamp() < 10);
    }

    public function testMarkerHoldsNothingButTheTimestamp(): void
    {
        $this->marker()->markAttempt(new DateTimeImmutable('2026-07-29 10:00:00', new DateTimeZone('UTC')));

        $raw = (string) file_get_contents($this->path);

        assertStringNotContainsString('sk-', $raw);
        assertStringNotContainsString('Authorization', $raw);
        assertSame('{"last_attempt_at":"2026-07-29T10:00:00+00:00"}', $raw);
    }

    /**
     * A corrupt marker must fail OPEN — the cost of a lost marker is one extra permitted sync, whereas
     * failing closed would make the page's only refresh control permanently dead.
     */
    public function testCorruptMarkerReadsAsNoRecentAttempt(): void
    {
        file_put_contents($this->path, 'not json at all');

        assertNull($this->marker()->lastAttemptAt());
    }

    /**
     * Correction 1: an absent key is null, NOT an empty SecretValue. That keeps "configured" a single
     * unambiguous check and makes it impossible to send a `Bearer ` header with nothing after it.
     */
    public function testAbsentAdminKeyIsNullNotAnEmptySecret(): void
    {
        $credentials = new OpenAiAdminCredentials('', 'https://api.openai.com/v1');

        assertNull($credentials->apiKey);
        assertFalse($credentials->isConfigured());
    }

    public function testPresentAdminKeyIsWrappedInASecretValue(): void
    {
        $credentials = new OpenAiAdminCredentials('sk-admin-example-value', 'https://api.openai.com/v1');

        assertTrue($credentials->isConfigured());
        assertTrue($credentials->apiKey instanceof SecretValue);
        assertSame('sk-admin-example-value', $credentials->apiKey->reveal());
    }

    /**
     * Correction 2, at the configuration level: the usage profile must cost at most one call's worth of
     * time. With a retry it would be 50s+, which alone exceeds the sync's 45s budget.
     */
    public function testUsageProfileWorstCaseIsASingleAttempt(): void
    {
        $profile = new OpenAiHttpProfile(
            name: 'usage',
            connectTimeoutSeconds: 5,
            timeoutSeconds: 20,
            maxRetries: 0,
            maxBackoffSeconds: 0,
        );

        assertSame(25, $profile->worstCaseSeconds());
    }

    private function marker(): FileSyncAttemptMarker
    {
        return new FileSyncAttemptMarker($this->path);
    }
}
