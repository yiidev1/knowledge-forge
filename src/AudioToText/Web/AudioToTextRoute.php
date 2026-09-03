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
}
