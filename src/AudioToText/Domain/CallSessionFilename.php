<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function preg_match;

/**
 * The call session id inside a recording's filename, where the provider's own naming was kept.
 *
 * ## Why a filename is evidence here and not a guess
 *
 * The provider names every recording after the call it belongs to, and the channel suffix is part of
 * that name: `22449119.wav`, `22449119-caller.wav`, `22449119-callee.wav`. That rule lives in
 * {@see \App\Integration\Order58Recording\RecordingChannel::fileNameFor()} and is what the importer
 * writes; it is also what an operator downloading from the diagnostic page and re-uploading by hand
 * ends up with, because they keep the name they were given.
 *
 * So reading the id back out is recovering a value the provider supplied, not inferring a relationship
 * from things that merely correlate. Store, order and duration are deliberately not consulted: an order
 * can hold several calls, and two recordings being the same length says nothing at all.
 *
 * ## What it refuses
 *
 * Anything that is not a bare run of digits with at most a known channel suffix. A file somebody named
 * `kongs-kitchen-monday.wav`, a re-save called `22449119 (1).wav`, and an id padded out with letters all
 * return null rather than a best effort — a wrong link would put one call's words on another call's
 * page, which is worse than no link at all.
 */
final class CallSessionFilename
{
    /**
     * Digits, then optionally `-caller` or `-callee`, then `.wav`. Case-insensitive on the suffix and
     * the extension only; the id itself has no letters to fold.
     */
    private const PATTERN = '/^(\d{6,20})(?:-(?:caller|callee))?\.wav$/i';

    public static function sessionIdIn(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        return preg_match(self::PATTERN, $filename, $matches) === 1 ? $matches[1] : null;
    }
}
