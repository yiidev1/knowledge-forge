<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

/**
 * What happened to one offered recording: it was queued, or it was refused and here is why.
 *
 * Two states rather than a nullable id, because "no conversation" and "no conversation, and these are the
 * three things wrong with the file" are different answers and a caller must not be able to read the first
 * when the second is true.
 */
final readonly class IngestionResult
{
    /**
     * @param list<string> $problems uploader-facing sentences, empty when the recording was queued
     */
    private function __construct(
        public ?string $conversationPublicId,
        public array $problems,
    ) {}

    public static function queued(string $conversationPublicId): self
    {
        return new self($conversationPublicId, []);
    }

    /**
     * @param non-empty-list<string> $problems
     */
    public static function rejected(array $problems): self
    {
        return new self(null, $problems);
    }

    public function wasQueued(): bool
    {
        return $this->conversationPublicId !== null;
    }
}
