<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

use function fclose;
use function flock;
use function is_resource;

use const LOCK_UN;

/** One held streaming slot. Released explicitly, and on destruction as a backstop. */
final class RecordingStreamSlot
{
    /** @param resource|null $handle */
    public function __construct(private mixed $handle) {}

    public static function unthrottled(): self
    {
        return new self(null);
    }

    public function release(): void
    {
        $handle = $this->handle;
        $this->handle = null;

        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
