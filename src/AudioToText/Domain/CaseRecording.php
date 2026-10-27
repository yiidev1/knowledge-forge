<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * One Case Order call recording an order row can offer: enough to build its Play and Download
 * addresses, and nothing else.
 *
 * In particular not the provider's URL. It carries the customer's phone number and the page has no use
 * for it: the audio is fetched on the server, by recording id, when somebody presses Play.
 */
final readonly class CaseRecording
{
    public function __construct(
        public int $recordingId,
        public string $callSessionId,
        /** 1-based, in the order the provider listed the order's calls and their files. */
        public int $number,
    ) {}
}
