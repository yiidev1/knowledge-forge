<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\AudioProviderDefaultInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_key_first;
use function array_key_exists;
use function is_array;
use function is_object;

/**
 * The transcription default, read straight from the audio settings table.
 *
 * Exactly the shape of {@see DbStoreAudioCounts}: a plain `Query` against one audio table, no
 * cross-module class reference, no shared abstraction between the two modules. That is what lets the
 * store-audio page show an Audio-to-Text setting while `ModuleIsolationTest` still passes.
 *
 * Read-only by design — see {@see AudioProviderDefaultInterface}. The form on the page posts to the
 * audio module's own route, addressed by route name.
 */
final readonly class DbAudioProviderDefault implements AudioProviderDefaultInterface
{
    private const SETTINGS = '{{%audio_to_text_settings}}';

    /**
     * Mirrors the audio module's provider list and the column's `CHECK` constraint.
     *
     * First entry is the fallback, and it is the local engine on purpose: a missing or unreadable row
     * must not be able to select a metered cloud service on somebody's behalf.
     */
    private const CHOICES = [
        'WHISPER' => 'Whisper (local)',
        'DEEPGRAM' => 'Deepgram (cloud)',
    ];

    public function __construct(private ConnectionInterface $connection) {}

    public function current(): string
    {
        $row = (new Query($this->connection))
            ->select(['default_transcription_provider'])
            ->from(self::SETTINGS)
            ->where(['id' => 1])
            ->limit(1)
            ->one();

        if (!is_array($row) && !is_object($row)) {
            return $this->fallback();
        }

        $row = (array) $row;
        $stored = $row['default_transcription_provider'] ?? null;

        if ($stored === null) {
            return $this->fallback();
        }

        $value = (string) $stored;

        // A value this module does not recognise is not an error to raise on a directory page: it means
        // the audio module has moved ahead of this list. Show the safe default rather than a blank.
        return array_key_exists($value, self::CHOICES) ? $value : $this->fallback();
    }

    public function choices(): array
    {
        return self::CHOICES;
    }

    private function fallback(): string
    {
        return array_key_first(self::CHOICES);
    }
}
