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
    public const JOB = 'audio-to-text.job';
    public const JOB_STATUS = 'audio-to-text.job.status';
    public const JOB_DOWNLOAD = 'audio-to-text.job.download';
}
