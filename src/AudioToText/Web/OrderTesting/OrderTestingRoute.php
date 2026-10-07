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
}
