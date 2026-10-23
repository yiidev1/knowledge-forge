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
        /**
         * Whether this reader may mark an order as checked over.
         *
         * True wherever a reader may change what the page says about an order, which is the same
         * answer as assignment: Order Testing yes, the read-only practice surfaces no. The template
         * also requires the surface to be a practice one, so the Audio-to-Text store page — which is
         * about recordings rather than orders — never renders the column despite holding every other
         * permission.
         */
        public bool $canVerifyOrders,
        /**
         * Whether this reader's system-voice playback is recorded, and offered the gap control.
         *
         * True on the agent practice surface alone. It is not a relaxation of {@see readOnly()} — that
         * profile stays entirely false, and this is a third profile beside it — but it does decide two
         * visible things: the Message Gap dropdown is rendered, and the dialog is given a CSRF token
         * to post its playback events with. A surface answering false emits neither, and the endpoint
         * is not mounted for it either.
         */
        public bool $canTrackPlayback,
        /**
         * Whether this reader may see what agents listened to.
         *
         * Read-only reporting, and a separate question from every other flag here: it is not about
         * changing a recording but about reading an audit of somebody else's practice. True for the
         * two realms that manage the orders agents practise on, false for the agents themselves — no
         * agent sees another agent's history, and none sees their own here.
         */
        public bool $canViewAgentEvents,
        /**
         * Whether this reader may fetch the original order's receipt from Order58.
         *
         * A capability of its own rather than a corollary of "can see the order", because it is the
         * only control on this table that spends a call on a third party and shows a customer's
         * printed order. The route that serves it is mounted in one route group; this flag is what
         * keeps the link out of the surfaces whose groups would refuse it anyway.
         */
        public bool $canViewOrderReceipts,
        /**
         * Whether this reader may see the conversation as **text**.
         *
         * Separate from every other flag here because it is the one thing Practice Orders is not
         * about. That surface exists so somebody can listen to a call and recreate the order from
         * what they hear; the written transcript beside it is the answer sheet. Audio stays, the
         * words go.
         *
         * False removes the markup rather than hiding it: no "View conversation" button, no turn
         * container for the script to fill, and no way through to the original transcript.
         */
        public bool $canReadConversation,
        /**
         * Whether the provider's own order number is shown.
         *
         * Split from {@see $canViewOrderReceipts} because the two columns arrived together and are
         * not the same decision: Seq is one more fact about an order a reader is already looking at,
         * while a receipt spends a call on a third party and shows a customer's printed order. The
         * practice surfaces get the first and not the second.
         */
        public bool $canViewOrderSeq,
    ) {}

    /** Everything — what this page has always offered, and still offers unchanged. */
    public static function full(): self
    {
        return new self(true, true, true, true, true, true, true, true, true, false, true, true, true, true);
    }

    /**
     * Look and listen, and nothing else.
     *
     * Reading a transcript, playing the original recording and playing AI audio that already exists are
     * not in this list because they are not capabilities: they are what the page *is*. What is listed
     * here is every way to change something, and an agent has none of them.
     */
    /**
     * The agent's own: read-only in every respect, and recorded.
     *
     * Deliberately not `readOnly()` with a flag flipped by the caller — a profile is a decision about
     * a surface, and the two surfaces that are read-only differ in exactly this one way. Spelling the
     * agent's out keeps `readOnly()` answering false to everything, which is what the admin practice
     * page still needs it to mean.
     */
    public static function agentPractice(): self
    {
        return new self(false, false, false, false, false, false, false, false, false, true, false, false, false, true);
    }

    public static function readOnly(): self
    {
        return new self(false, false, false, false, false, false, false, false, false, false, false, false, false, true);
    }
}
