<?php

declare(strict_types=1);

namespace App\AudioToText\Web\OrderTesting;

/**
 * Order Testing's own route names.
 *
 * ## Why a second surface over one set of recordings
 *
 * Order Testing is an administrative view of the same audio Audio-to-Text already holds — the same
 * conversations, jobs, transcripts and renditions, read through the same proven services. What it does
 * **not** share is its presentation: its own routes, its own actions, its own template and its own
 * stylesheet, so its columns, wording, controls and workflow can change without touching the page
 * Audio-to-Text administrators use every day.
 *
 * The alternative was a flag threaded through the existing page — `if ($isOrderTesting)` in the template
 * and in the script. That couples the two surfaces at exactly the place they are meant to diverge, and
 * every later Order Testing change becomes a change to Audio-to-Text with a condition around it.
 *
 * ## Why it lives inside the Audio-to-Text module
 *
 * {@see \App\Tests\Unit\AudioToText\ModuleIsolationTest::testNoExistingModuleDependsOnAudioToText()}
 * forbids any file outside `src/AudioToText/` from naming `App\AudioToText`. A top-level `src/OrderTesting`
 * could therefore not reuse one audio service — it would have to copy the repository, the grouping, the
 * store lookup and the demo links, and then keep four copies honest. So the boundary is drawn where it
 * costs nothing: a sibling **web surface** inside the module whose domain it reads.
 *
 * ## What is shared, deliberately
 *
 * Job-scoped endpoints — status polling, original-file serving, AI-audio serving and generation, and the
 * whole review surface — are addressed by a job's public id and are not pages. They are infrastructure,
 * some of it security-sensitive file serving, and a second copy would be a second thing to get wrong.
 * Group-scoped endpoints are Order Testing's own, because a replacement posted from this page must come
 * back to this page.
 */
final class OrderTestingRoute
{
    /** The store picker: which store's recordings to test against. */
    public const PAGE = 'order-testing';

    /** One store's recordings. GET renders, POST uploads — the same shape the audio page uses. */
    public const STORE = 'order-testing.store';

    /** What is still downloading for this store. The audio page's own reader, mounted here too. */
    public const STORE_ARRIVING = 'order-testing.store.arriving';

    public const STORE_GROUP_TRANSCRIPTS = 'order-testing.store.group.transcripts';

    public const STORE_GROUP_TTS_OPTIONS = 'order-testing.store.group.tts-options';

    public const STORE_GROUP_RECORDINGS = 'order-testing.store.group.recordings';

    public const STORE_GROUP_REPLACE = 'order-testing.store.group.replace';

    // ---------------------------------------------------------------- the demo-order half of Order Testing
    //
    // These three are served by `App\OrderTesting`, a module of its own, and are named here only as
    // strings — a route name is not a namespace, so nothing about module isolation is relaxed by
    // linking to them from this template.
    //
    // That split does not contradict the reasoning above. What belongs in this module is the audio
    // SURFACE, because it reuses the audio domain. Demo orders, test attempts and source-versus-demo
    // comparison reuse none of it: they read Order58 order documents and files a listener wrote, and
    // keeping them here would have put a filesystem importer and two tables inside the transcription
    // module for no reason other than where the link happens to be rendered.

    /** POST. Records who is testing, then redirects to Order58. Never a plain external link. */
    public const DEMO_URL = 'order-testing.demo-url';

    /**
     * The "View (N)" destination: one source order's practice history, grouped by who practised it.
     *
     * The name is unchanged on purpose. Every link that already points here — the table cell, the
     * comparison page's back link, the agent surface's own copy — keeps working and now lands on the
     * history rather than on a flat list of imports.
     */
    public const DEMO_ORDERS = 'order-testing.demo-orders';

    /**
     * The flat list of every imported demo order, which "View (N)" used to open.
     *
     * Still here, one click away under All Demo Orders. It answers a different question: the history
     * says what somebody did, this says what was imported.
     */
    public const DEMO_ORDERS_ALL = 'order-testing.demo-orders.all';

    /** One demo order beside the source order it was recreated from. */
    public const DEMO_COMPARE = 'order-testing.demo-compare';

    /** The same demo order printed as a receipt. Read-only, and a page of its own so it can be printed. */
    public const DEMO_RECEIPT = 'order-testing.demo-receipt';

    /**
     * The **original** order's receipt, fetched from Order58 rather than rendered here.
     *
     * A different thing from {@see DEMO_RECEIPT} beside it, and named so that the two cannot be
     * confused: that one prints a demo order this application already holds, this one asks the
     * provider for the real order's printed receipt and streams the image back.
     */
    public const ORDER_RECEIPT = 'order-testing.order-receipt';

    /**
     * Run the demo-order import now, from the picker.
     *
     * Manual because nothing schedules it yet. When something does, it will call the same service
     * under the same lock, so this button neither competes with it nor becomes redundant.
     */
    public const SYNC_DEMO_ORDERS = 'order-testing.sync-demo-orders';

    /**
     * Putting a topic on one order, and on several at once.
     *
     * On this surface because this is where training content is *managed*. The practice surfaces show
     * which topic an order carries and never change it: they render no control that posts here, and
     * their route groups do not mount these addresses at all.
     */
    public const TOPIC_ASSIGN = 'order-testing.topic.assign';

    public const TOPIC_BULK = 'order-testing.topic.bulk';

    /**
     * Where an order is marked as checked over, or that is taken back.
     *
     * Order-scoped rather than store-scoped: the thing being verified is one source order, and the
     * pair in the path is the identity the whole application uses for one.
     */
    public const VERIFICATION = 'order-testing.order.verification';

    /** What agents listened to, for the two realms that manage the orders they listened to. */
    public const PLAYBACK_SUMMARY = 'order-testing.agent-playback.summary';
    public const PLAYBACK_HISTORY = 'order-testing.agent-playback.history';
}
