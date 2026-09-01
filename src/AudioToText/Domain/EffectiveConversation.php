<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\AudioToText\Domain\Speaker\SpeakerUtterance;

/**
 * The conversation a reader should be shown, and the agent/customer text that goes with it.
 *
 * One object because the three travel together and must never disagree. Today the page reads the turns
 * from `speaker_segments` and the two text blocks from separate stored columns — two representations of
 * one fact, kept in step only by the pipeline writing both at the same moment. Once an administrator can
 * correct attribution, that arrangement would let the bubbles and the cards contradict each other.
 * Deriving all three from one place makes the contradiction unrepresentable.
 *
 * `isReviewed` is false for every job today: nothing writes a reviewed conversation yet, so this is
 * still the machine's own output. The flag exists so the surfaces that will need to say "reviewed" have
 * somewhere to read it from, and so wiring it now is a no-op that can be verified as one.
 */
final readonly class EffectiveConversation
{
    /**
     * @param list<SpeakerUtterance> $utterances chronological turns
     */
    public function __construct(
        public array $utterances,
        public ?string $agentText,
        public ?string $customerText,
        public bool $isReviewed,
        /**
         * Whether Agent/Customer may be shown as fact.
         *
         * Read rather than re-derived, so the page cannot reach a different conclusion from the
         * service that wrote the data.
         */
        public bool $rolesConfirmed,
    ) {}

    /**
     * Whether either role column carries text.
     *
     * Mirrors {@see TranscriptionJob::hasSeparatedText()} on purpose: it feeds the same
     * `ConversationView` argument, which refuses to publish role labels for a job whose aggregate text
     * is missing. Reading it from here rather than from the job keeps that gate pointed at whichever
     * conversation is actually on screen.
     */
    public function hasSeparatedText(): bool
    {
        return $this->agentText !== null || $this->customerText !== null;
    }

    public function isEmpty(): bool
    {
        return $this->utterances === [];
    }
}
