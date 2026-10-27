<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\RecordingUrlPolicy;
use Codeception\Test\Unit;

use function str_repeat;

/**
 * The server-side request forgery boundary for Case Order recordings.
 *
 * A stored link is fetched by this server when somebody presses Play, so the policy has to accept the
 * provider's real links and refuse every way a link could name somewhere else.
 */
final class RecordingUrlPolicyTest extends Unit
{
    private const REAL_SHAPE = 'https://order58.xrainbow.com/api/external/recording/fetch/21403649'
        . '?time=2026-07-29&company=SWCC&name=15594361-10000000000-21403649';

    public function testTheProvidersOwnShapeIsAccepted(): void
    {
        self::assertTrue(RecordingUrlPolicy::accepts(self::REAL_SHAPE));
        self::assertSame('21403649', RecordingUrlPolicy::sessionIdOf(self::REAL_SHAPE));
    }

    public function testTheExplicitDefaultPortIsAccepted(): void
    {
        self::assertTrue(RecordingUrlPolicy::accepts(
            'https://order58.xrainbow.com:443/api/external/recording/fetch/1?time=2026-07-29',
        ));
    }

    /**
     * @dataProvider refused
     */
    public function testEveryOtherShapeIsRefused(string $url): void
    {
        self::assertFalse(RecordingUrlPolicy::accepts($url));
        self::assertNull(RecordingUrlPolicy::sessionIdOf($url));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'plain http' => ['http://order58.xrainbow.com/api/external/recording/fetch/1'],
            'another host' => ['https://example.com/api/external/recording/fetch/1'],
            'a lookalike host' => ['https://order58.xrainbow.com.evil.test/api/external/recording/fetch/1'],
            'a subdomain' => ['https://x.order58.xrainbow.com/api/external/recording/fetch/1'],
            'user info' => ['https://user@order58.xrainbow.com/api/external/recording/fetch/1'],
            'user info hiding a host' => ['https://order58.xrainbow.com@169.254.169.254/api/external/recording/fetch/1'],
            'another port' => ['https://order58.xrainbow.com:8443/api/external/recording/fetch/1'],
            'another path' => ['https://order58.xrainbow.com/api/external/other/1'],
            'a traversal' => ['https://order58.xrainbow.com/api/external/recording/fetch/../../admin'],
            'a second segment' => ['https://order58.xrainbow.com/api/external/recording/fetch/1/2'],
            'no session' => ['https://order58.xrainbow.com/api/external/recording/fetch/'],
            'a non-numeric session' => ['https://order58.xrainbow.com/api/external/recording/fetch/abc'],
            'a fragment' => ['https://order58.xrainbow.com/api/external/recording/fetch/1#x'],
            'whitespace' => [' https://order58.xrainbow.com/api/external/recording/fetch/1'],
            'a newline' => ["https://order58.xrainbow.com/api/external/recording/fetch/1\n"],
            'an IP literal' => ['https://127.0.0.1/api/external/recording/fetch/1'],
            'empty' => [''],
            'too long' => ['https://order58.xrainbow.com/api/external/recording/fetch/1?x=' . str_repeat('a', 2100)],
            'a file URL' => ['file:///etc/passwd'],
        ];
    }
}
