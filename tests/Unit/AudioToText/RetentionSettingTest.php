<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\QueuedAudioStorage;
use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;
use function str_contains;
use function str_repeat;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;

/**
 * Retention is one number in `.env`, and `0` means keep everything forever.
 *
 * These conversations are the product — they are meant to be read back later — so the default had to
 * stop being a 24-hour purge. This pins both halves: that indefinite really is indefinite, and that
 * turning expiry back on is still a setting rather than a code change.
 */
final class RetentionSettingTest extends TestCase
{
    public function testZeroMeansKeepIndefinitely(): void
    {
        $settings = AudioToTextSettingsFactory::create(retentionSeconds: 0);

        $this->assertTrue($settings->transcription->retainsIndefinitely());
        $this->assertNull(
            $settings->transcription->retentionHours(),
            'There is no "hours kept" figure when nothing is deleted.',
        );
    }

    /** The shipped default, asserted directly so a future edit to it fails here rather than in production. */
    public function testTheDefaultIsIndefinite(): void
    {
        $this->assertTrue(AudioToTextSettingsFactory::create()->transcription->retainsIndefinitely());
    }

    /**
     * A negative value would be a misconfiguration; treating it as indefinite is the safe reading,
     * because the alternative is computing an expiry in the past and deleting everything immediately.
     */
    public function testANegativeValueIsTreatedAsIndefinite(): void
    {
        $this->assertTrue(AudioToTextSettingsFactory::create(retentionSeconds: -1)->transcription->retainsIndefinitely());
    }

    /**
     * @dataProvider windowProvider
     */
    public function testAPositiveValueEnablesExpiry(int $seconds, int $expectedHours): void
    {
        $settings = AudioToTextSettingsFactory::create(retentionSeconds: $seconds);

        $this->assertFalse($settings->transcription->retainsIndefinitely());
        $this->assertSame($expectedHours, $settings->transcription->retentionHours());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function windowProvider(): array
    {
        return [
            '24 hours' => [86400, 24],
            '30 days' => [2592000, 720],
            '1 hour' => [3600, 1],
            // Rounds up rather than to zero: "kept for 0 hours" would read as "not kept".
            'sub-hour' => [60, 1],
        ];
    }

    /**
     * Retained recordings must live outside the tree the orphan sweep walks.
     *
     * This is the structural half of the guarantee: the sweeper deletes directories under `jobs/`, so
     * keeping recordings in a sibling tree makes "the sweeper cannot reach them" a property of the
     * layout rather than a rule someone has to remember not to break.
     */
    public function testRetainedRecordingsLiveOutsideTheSweptWorkspace(): void
    {
        $transcription = AudioToTextSettingsFactory::create()->transcription;

        $jobs = $transcription->jobsDirectory();
        $recordings = $transcription->recordingsDirectory();

        $this->assertNotSame($jobs, $recordings);
        $this->assertFalse(
            str_contains($recordings, $jobs . '/'),
            'Retained recordings must not sit inside the sweepable workspace.',
        );
        $this->assertFalse(
            str_contains($jobs, $recordings . '/'),
            'The sweepable workspace must not sit inside the retained store either.',
        );
    }

    /**
     * The orphan sweep for recordings removes only what no job row owns.
     *
     * A recording is retained *because* a row references it, so a missing row is what defines an orphan
     * — which is why this can never remove a recording that is still retained, whatever the retention
     * setting says.
     */
    public function testOrphanedRecordingsAreSweptButRetainedOnesAreNot(): void
    {
        $base = sys_get_temp_dir() . '/kf-a2t-' . uniqid('', true);
        $settings = AudioToTextSettingsFactory::create(temporaryDirectory: $base);
        $storage = new QueuedAudioStorage($settings);
        $storage->prepareBaseDirectories();

        $retained = str_repeat('a', 32);
        $orphan = str_repeat('b', 32);

        foreach ([$retained, $orphan] as $publicId) {
            mkdir($settings->transcription->recordingsDirectory() . '/' . $publicId, 0o700, true);
            file_put_contents(
                $settings->transcription->recordingsDirectory() . '/' . $publicId . '/source.wav',
                'audio',
            );
            touch($settings->transcription->recordingsDirectory() . '/' . $publicId, time() - 86400);
        }

        // Only the first id still has a job row.
        $removed = $storage->sweepOrphanedRecordings(
            static fn(string $publicId): bool => $publicId === $retained,
            600,
            time(),
        );

        $this->assertSame([$orphan], $removed);
        $this->assertFileExists($settings->transcription->recordingsDirectory() . '/' . $retained . '/source.wav');
        $this->assertDirectoryDoesNotExist($settings->transcription->recordingsDirectory() . '/' . $orphan);

    }

    /**
     * A recording younger than the stale window is never swept, even with no job row.
     *
     * That window is what stops a job mid-flight being collected out from under itself: the row and the
     * directory do not appear at the same instant, and the gap must not be mistaken for an orphan.
     */
    public function testARecentRecordingIsNeverSweptEvenWithoutARow(): void
    {
        $base = sys_get_temp_dir() . '/kf-a2t-' . uniqid('', true);
        $settings = AudioToTextSettingsFactory::create(temporaryDirectory: $base);
        $storage = new QueuedAudioStorage($settings);
        $storage->prepareBaseDirectories();

        $recent = str_repeat('c', 32);
        mkdir($settings->transcription->recordingsDirectory() . '/' . $recent, 0o700, true);

        $this->assertSame(
            [],
            $storage->sweepOrphanedRecordings(static fn(): bool => false, 600, time()),
        );
        $this->assertDirectoryExists($settings->transcription->recordingsDirectory() . '/' . $recent);
    }

    /** The worker lock is outside both trees, so neither sweep can reach it. */
    public function testTheWorkerLockIsOutsideBothTrees(): void
    {
        $transcription = AudioToTextSettingsFactory::create()->transcription;

        $this->assertFalse(str_contains($transcription->workerLockFile(), $transcription->jobsDirectory() . '/'));
        $this->assertFalse(
            str_contains($transcription->workerLockFile(), $transcription->recordingsDirectory() . '/'),
        );
    }
}
