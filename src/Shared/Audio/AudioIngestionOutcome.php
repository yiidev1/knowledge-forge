<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/**
 * What became of one offered recording, in terms neither module has to translate.
 *
 * Three outcomes rather than two, because the caller has to tell them apart to decide what to record:
 * a recording the pipeline refused is settled and must not be retried, while a pipeline that could not
 * accept it *right now* — no disk, a queue at its ceiling — is worth trying again later. Collapsing
 * those into one "failed" would either strand good recordings or retry bad ones for ever.
 */
final readonly class AudioIngestionOutcome
{
    private function __construct(
        public ?string $conversationPublicId,
        /** @var list<string> uploader-facing sentences */
        public array $problems,
        public bool $isTransient,
    ) {}

    public static function queued(string $conversationPublicId): self
    {
        return new self($conversationPublicId, [], false);
    }

    /**
     * The recording itself is unacceptable: too big, not audio, too long. Retrying changes nothing.
     *
     * @param non-empty-list<string> $problems
     */
    public static function rejected(array $problems): self
    {
        return new self(null, $problems, false);
    }

    /** This application could not accept it at this moment. The recording may still be fine. */
    public static function unavailable(string $problem): self
    {
        return new self(null, [$problem], true);
    }

    public function wasQueued(): bool
    {
        return $this->conversationPublicId !== null;
    }

    /** The first sentence, which is the one worth showing; the rest say the same thing differently. */
    public function firstProblem(): string
    {
        return $this->problems[0] ?? 'The recording could not be accepted.';
    }
}
