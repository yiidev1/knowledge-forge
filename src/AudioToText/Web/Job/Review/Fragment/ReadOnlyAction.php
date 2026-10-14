<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Fragment;

/**
 * The same conversation fragment, for a reader who may not change it.
 *
 * ## Why a subclass and not a flag on the request
 *
 * Which surface asked is a property of the route, not of anything the caller sends. A query parameter
 * or a header would be something a browser could change; a route is not. So the read-only surfaces
 * mount this class and the editing ones mount its parent, and there is no request in which the two
 * could be confused.
 *
 * ## What differs
 *
 * Everything about the conversation itself is inherited: the same view model, the same turns, the same
 * audio, the same borrowed conversations. What this withholds is every address that leads somewhere a
 * viewing reader cannot go —
 *
 * - the per-turn edit, merge and move endpoints, and the Confirm flag, so the dialog builds no edit
 *   form and the DOM of a read-only page contains none;
 * - the Generate action and its button;
 * - **the full correction page**, which is what the "Customer recording" and "Agent recording" links
 *   point at. Those were the quiet one: they rendered as ordinary links, and following one took the
 *   reader to a page their gate refuses, which the dialog then displayed inside itself.
 *
 * The endpoints behind all of them are not mounted for these surfaces either. This is the half that
 * keeps the page honest; that is the half that keeps it safe.
 */
readonly class ReadOnlyAction extends Action
{
    protected function canEditTranscript(): bool
    {
        return false;
    }

    protected function reviewPageRoute(): ?string
    {
        return null;
    }
}
