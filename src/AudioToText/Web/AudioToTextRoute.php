<?php

declare(strict_types=1);

namespace App\AudioToText\Web;

/**
 * Route names for the feature.
 *
 * Kept in the feature's own directory rather than added to a shared route-name class, so removing the
 * feature is a directory deletion plus a handful of config lines and cannot leave a dangling constant
 * behind that still compiles.
 */
final class AudioToTextRoute
{
    public const PAGE = 'audio-to-text';
    public const JOBS = 'audio-to-text.jobs';

    /**
     * One store's audio: the upload form and that store's own history.
     *
     * The store id lives in the URL because it is the only place it may come from — a posted store id
     * would let one store's page write onto another store's history.
     */
    public const STORE = 'audio-to-text.store';

    /**
     * One logical conversion, whether it was recorded as one mixed file or as a Customer and an Agent
     * file. This is what a row in the store history opens.
     */
    public const CONVERSION = 'audio-to-text.conversion';
    public const JOB = 'audio-to-text.job';
    public const JOB_STATUS = 'audio-to-text.job.status';
    public const JOB_DOWNLOAD = 'audio-to-text.job.download';

    /**
     * Clean AI training audio generated from a transcript, so a new agent can hear a call they could not
     * otherwise follow.
     *
     * The page is addressed by the **conversion**, because a Customer + Agent pair is one call and both
     * sides belong on one screen. The two actions underneath are addressed by the **job**, which is what
     * a rendition and its file actually hang off — so the endpoint that streams bytes resolves straight
     * to the recording it is authorising, with no conversation-to-child hop in between.
     */
    public const CONVERSION_AI_AUDIO = 'audio-to-text.conversion.ai-audio';
    public const JOB_AI_AUDIO_GENERATE = 'audio-to-text.job.ai-audio.generate';
    public const JOB_AI_AUDIO_FILE = 'audio-to-text.job.ai-audio.file';

    /** The conversation on its own — where the conversions list's View action goes. */
    public const JOB_CONVERSATION = 'audio-to-text.job.conversation';

    /**
     * The machine's own conversation, read-only and permanent.
     *
     * Separate from JOB_CONVERSATION because that one follows the corrections and this one never does —
     * the two exist precisely so a reader can compare them.
     */
    public const JOB_ORIGINAL = 'audio-to-text.job.original';

    /**
     * Speaker correction. One route per operation rather than one endpoint dispatching on a field, so
     * the route name, the audited operation and the button a person pressed all say the same thing.
     */
    public const JOB_REVIEW = 'audio-to-text.job.review';
    public const JOB_REVIEW_MOVE = 'audio-to-text.job.review.move';
    public const JOB_REVIEW_MOVE_TEXT = 'audio-to-text.job.review.move-text';
    public const JOB_REVIEW_SPLIT = 'audio-to-text.job.review.split';
    public const JOB_REVIEW_MERGE = 'audio-to-text.job.review.merge';
    public const JOB_REVIEW_TEXT = 'audio-to-text.job.review.text';
    public const JOB_REVIEW_CONFIRM = 'audio-to-text.job.review.confirm';
    public const JOB_REVIEW_REVERT = 'audio-to-text.job.review.revert';

    /**
     * The global default transcription provider.
     *
     * POST only, and owned here rather than by the page that renders the form. `/admin/order58/store-audio`
     * is where an administrator naturally changes it, but the rules about which providers exist and
     * which may be selected belong to this module; Order58 addresses this by route name, which is not a
     * namespace and so does not breach module isolation.
     */
    public const SETTINGS_DEFAULT_PROVIDER = 'audio-to-text.settings.default-provider';
}
