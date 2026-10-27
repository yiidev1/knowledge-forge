<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The data-source axis of the store picker: every store, those with uploaded audio, or those with
 * imported Case Orders.
 *
 * A separate axis from the source-active and knowledge-pipeline filters for the same reason those are
 * separate from each other — a store can be source-inactive and still hold a year of recordings, and
 * folding the two into one status would make both unanswerable.
 *
 * ## Why Case Orders is a third case rather than a flag on the other two
 *
 * The two populations do not contain one another. A store can hold recordings nobody has imported an
 * order for, and — far more commonly since the Case Order sync — orders nobody has recorded a call
 * for. "Uploaded audio" could therefore never be narrowed into "and also has orders" without
 * answering a different question from the one asked. Three named answers, each with its own count
 * and its own empty state, is the honest shape.
 *
 * The enum keeps its name: renaming it would touch the audio picker, Practice Orders and their
 * templates for no behavioural gain, and `ModuleIsolationTest` makes that a wider change than it
 * looks. The *wire* values are what an operator sees in a URL, and those stay as they were.
 */
enum StoreAudioFilter: string
{
    case All = 'all';
    case WithAudio = 'with';
    /** Stores holding at least one imported Case Order. Audio is not consulted. */
    case CaseOrders = 'case-orders';
    /**
     * Stores holding at least one Case Order with a call recording link.
     *
     * Read from the synchronised recording metadata, never from `audio_conversations`: these
     * recordings are streamed from the provider on demand, so a store can have hundreds of them and
     * nothing uploaded at all. Its card counts ORDERS with a recording, not links — an order whose call
     * was saved as three files is one order to practise.
     */
    case CaseRecordings = 'case-recordings';

    /**
     * Parse the `audio=` query parameter, falling back to the surface's own landing state.
     *
     * The fallback is a parameter rather than a constant because the two pickers land differently: the
     * audio page is neutral (every store) and Order Testing opens on the stores that actually hold
     * recordings. Both read the same words off the wire; only what "unspecified" means differs, and
     * each caller says which it is. An unrecognised value is treated as unspecified rather than as
     * `All`, so a typo lands an operator on the page they would have got with no parameter at all.
     */
    public static function fromRequest(?string $value, self $default = self::All): self
    {
        return self::tryFrom($value ?? '') ?? $default;
    }

    /**
     * The configured landing filter for Manage Orders — the one place the default is decided.
     *
     * `MANAGE_ORDERS_DEFAULT_SOURCE` is read as a string rather than declared as an environment
     * `enum`, which is the convention elsewhere in the SPEC. Deliberate: an unrecognised `enum` value
     * throws at boot, and that is right for a credential or an endpoint but wrong for which chip a
     * page opens on. A typo here should cost the configured default, not the site. So the fallback
     * lives here, where the words are already defined, and nothing else in the application has to
     * know what the setting can say.
     *
     * The wire values (`all`, `with`, `case-orders`) stay what a URL has always contained; these are
     * the configuration words, which read better in a `.env` and are free to differ.
     */
    public static function fromConfiguredDefault(string $configured): self
    {
        return match ($configured) {
            'uploaded_audio' => self::WithAudio,
            'all' => self::All,
            // `case_orders`, and anything unrecognised. See above for why this does not throw.
            default => self::CaseOrders,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All stores',
            self::WithAudio => 'Uploaded audio',
            self::CaseOrders => 'Order58 Case Orders',
            self::CaseRecordings => 'Case Order recordings',
        };
    }

    /**
     * What a store card's headline counts under this filter.
     *
     * The card is the headline for the page behind it, so the number and the word above it have to
     * describe the same population. Reading `order58_orders` under the word "Audio Recordings" — which
     * is what `ORDER58_CASE_ORDER_PRIMARY` used to produce on every card — is the specific mistake
     * this removes: the figure was right and the label was not.
     */
    public function cardMetricLabel(?bool $caseOrderPrimary = null): string
    {
        return match ($this) {
            self::CaseOrders => 'Case Orders',
            self::WithAudio => 'Audio Recordings',
            self::CaseRecordings => 'Orders With Recordings',
            // Unfiltered, the card follows whatever the page behind it lists.
            self::All => ($caseOrderPrimary ?? true) ? 'Case Orders' : 'Audio Recordings',
        };
    }

    /**
     * Whether the headline counts imported orders rather than recordings under this filter.
     *
     * Null means "whatever is configured", which is only ever the answer for `All` — the two named
     * filters each say plainly which population they are about, and a card under them must not
     * quietly count the other one.
     */
    public function countsImportedOrders(): ?bool
    {
        return match ($this) {
            self::CaseOrders => true,
            // Counted by its own query — orders with a recording link — rather than by either of the
            // two this answers for; the picker asks for those counts directly in this mode.
            self::WithAudio, self::CaseRecordings => false,
            self::All => null,
        };
    }
}
