<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * What the person looking at a store's recordings is allowed to do to them.
 *
 * ## Why this exists at all
 *
 * One template renders three surfaces now — Order Testing, Practice Orders and the agent practice
 * view — and the third may change nothing. The obvious implementations are both wrong:
 *
 * - **Hiding controls in CSS** leaves every endpoint addressable and every hook in the DOM. A page that
 *   only *looks* read-only is not read-only.
 * - **A single `$readOnly` flag** collapses eight different questions into one. It reads fine until a
 *   fourth surface wants six of the eight, and then every call site has to be re-decided at once.
 *
 * So each control asks its own question, and a control whose answer is false is **never emitted** — no
 * element, no `data-` hook, nothing for the script to bind to. The server refuses regardless, because
 * the agent routes simply do not mount the endpoints these controls would call; this is the other half
 * of that, so the page does not offer what the server would reject.
 *
 * ## Two profiles today, and that is deliberate
 *
 * `full()` is what administrators and operation users get, so **their rendered markup is unchanged** —
 * the existing Order Testing suite is the regression test for that. `readOnly()` is the agent's. The
 * flags are named individually anyway, because the next surface that wants a mixture should be able to
 * say so without rewriting the call sites.
 */
final readonly class ConversationCapabilities
{
    private function __construct(
        /** Add a recording to this store at all. */
        public bool $canUploadAudio,
        /** Open Manage Audio: replace a recording, or fill an empty channel. */
        public bool $canReplaceAudio,
        /** Ask for a recording that has never been transcribed to be transcribed now. */
        public bool $canTranscribe,
        /** Correct a transcript — the edit controls inside the conversation dialog. */
        public bool $canEditTranscript,
        /** Generate clean AI audio from a conversation. */
        public bool $canGenerateTts,
        /** Follow through to the full correction page. */
        public bool $canOpenFullEditor,
        /** Run the demo-order importer from this page. */
        public bool $canSyncDemoOrders,
        /** Put a practice topic on an order. */
        public bool $canAssignTopics,
    ) {}

    /** Everything — what this page has always offered, and still offers unchanged. */
    public static function full(): self
    {
        return new self(true, true, true, true, true, true, true, true);
    }

    /**
     * Look and listen, and nothing else.
     *
     * Reading a transcript, playing the original recording and playing AI audio that already exists are
     * not in this list because they are not capabilities: they are what the page *is*. What is listed
     * here is every way to change something, and an agent has none of them.
     */
    public static function readOnly(): self
    {
        return new self(false, false, false, false, false, false, false, false);
    }
}
