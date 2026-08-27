<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * The queue cap is one number in `.env`, and `0` means no cap.
 *
 * It counts QUEUED plus PROCESSING jobs across the whole installation — never per administrator. The
 * per-administrator limit that used to exist enforced "one at a time" in the upload form, which stopped
 * people queueing work rather than stopping the machine being overloaded; that job belongs to the
 * worker, which holds a lock and claims one row at a time.
 */
final class QueueLimitSettingTest extends TestCase
{
    public function testZeroMeansUnlimited(): void
    {
        $this->assertTrue(AudioToTextSettingsFactory::create(maxQueue: 0)->transcription->hasUnlimitedQueue());
    }

    /** The shipped default, asserted directly so changing it fails here rather than in production. */
    public function testTheDefaultIsUnlimited(): void
    {
        $this->assertTrue(AudioToTextSettingsFactory::create()->transcription->hasUnlimitedQueue());
    }

    /**
     * A negative value is a misconfiguration; reading it as unlimited is the safe direction, because
     * the alternative is a cap no upload can ever satisfy.
     */
    public function testANegativeValueIsTreatedAsUnlimited(): void
    {
        $this->assertTrue(AudioToTextSettingsFactory::create(maxQueue: -5)->transcription->hasUnlimitedQueue());
    }

    /**
     * A finite cap still works, so setting one later needs no code change.
     *
     * @dataProvider finiteLimitProvider
     */
    public function testAPositiveValueEnablesTheCap(int $limit): void
    {
        $settings = AudioToTextSettingsFactory::create(maxQueue: $limit);

        $this->assertFalse($settings->transcription->hasUnlimitedQueue());
        $this->assertSame($limit, $settings->transcription->maxQueue);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function finiteLimitProvider(): array
    {
        return [
            'one' => [1],
            'the old default' => [5],
            'fifty' => [50],
        ];
    }
}
