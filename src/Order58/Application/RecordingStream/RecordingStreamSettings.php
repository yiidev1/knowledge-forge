<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

/**
 * How recordings are streamed: how many may be fetched at once, and where from.
 */
final readonly class RecordingStreamSettings
{
    public function __construct(
        /**
         * Upstream fetches allowed at the same moment, across every PHP worker.
         *
         * Each one holds a PHP worker and a connection to the provider for as long as the file takes to
         * arrive. A cap keeps a burst of listeners from taking every worker the rest of the application
         * needs, and from presenting the provider with a burst it may rate-limit.
         */
        public int $maxConcurrent = 4,
        /**
         * Answer from local fixture files instead of the provider.
         *
         * Resolved before it reaches here: true only when it was asked for AND the environment is dev or
         * test, so a production server can never serve test audio, whatever its configuration says.
         */
        public bool $fixture = false,
        /** Where fixture WAV files live, `{session id}.wav`, with `default.wav` for any other session. */
        public string $fixtureDirectory = '',
        /** Where the concurrency slots' lock files live. */
        public string $lockDirectory = '',
    ) {}
}
