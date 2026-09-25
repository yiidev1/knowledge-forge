<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Integration\Order58Recording\ChannelDiagnosis;

/**
 * What one fetch attempt produced: a file on disk, or a reason there is none.
 *
 * The path is present only on success, and the caller is expected to consume it — ingestion moves the
 * file away. On every other outcome the temporary file has already been removed, so there is nothing to
 * clean up and nothing to forget to clean up.
 */
final readonly class RecordingDownload
{
    private function __construct(
        public ?string $path,
        public int $bytes,
        public ChannelDiagnosis $diagnosis,
        public int $status,
    ) {}

    public static function succeeded(string $path, int $bytes, ChannelDiagnosis $diagnosis, int $status): self
    {
        return new self($path, $bytes, $diagnosis, $status);
    }

    public static function failed(ChannelDiagnosis $diagnosis, int $status, int $bytes = 0): self
    {
        return new self(null, $bytes, $diagnosis, $status);
    }

    public function wasFetched(): bool
    {
        return $this->path !== null;
    }
}
