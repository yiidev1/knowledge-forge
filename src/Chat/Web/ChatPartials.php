<?php

declare(strict_types=1);

namespace App\Chat\Web;

/**
 * Absolute filesystem paths to the chat partials shared across realms.
 *
 * These must be absolute. A view name is only treated as already-resolved when it starts with `/`; anything
 * else — including a `@src/...` alias, which the view renderer does NOT expand in a view name — falls through
 * to the renderer's configured view path, which an action that sets only a layout does not have, and the
 * render dies with "The view path is not set.".
 *
 * Anchoring on `__DIR__` removes that trap and keeps the agent templates from needing to know where the Chat
 * module lives. Same reasoning as {@see \App\Chat\Web\Sources\SourceViews}.
 */
final class ChatPartials
{
    /** The "rate this answer" control rendered under every active assistant answer. */
    public static function scorePanel(): string
    {
        return __DIR__ . '/_partial/score-panel';
    }

    /** The dialog a source chip opens. Rendered once per chat page; filled in by `admin.js`. */
    public static function sourceModal(): string
    {
        return __DIR__ . '/_partial/source-modal';
    }
}
