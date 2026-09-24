<?php

declare(strict_types=1);

namespace App\AudioToText\Web;

use Yiisoft\Html\Html;

/**
 * The small icons the conversation screens draw, in one place.
 *
 * Inline SVG rather than an icon font or a sprite file: the CSP is `default-src 'self'` with no
 * external origins, and markup needs no request at all.
 *
 * ## Why a class rather than a partial
 *
 * Two screens draw these — the full correction page and the Details dialog on the store page — and a
 * second copy of a path string is a second icon that starts identical and stops being so. A partial
 * would render markup; these have to be *returned* so a caller can put one inside a button it is
 * building. So: constants for the paths, one method for the wrapper.
 *
 * The label is not decoration. Every one of these sits alone inside a round button with no text, so
 * without the visually-hidden span a screen reader announces "button" and nothing else.
 */
final class AudioToTextIcons
{
    /** Correct the wording. */
    public const PENCIL = '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>';

    /** A clock face with a hand — the icon every interface already uses for "what happened before". */
    public const CLOCK = '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>';

    /** Six dots, the handle everyone already reads as "drag me". */
    public const GRIP = '<circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/>'
        . '<circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/>'
        . '<circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>';

    /**
     * The four transport controls for the browser's own voice.
     *
     * Outlined rather than filled, because everything else here is: a solid triangle beside a hairline
     * pencil reads as two icon sets rather than one.
     *
     * {@see PLAY} and {@see RESUME} are deliberately *not* the same glyph. They sit next to each other
     * in the same toolbar, and two identical triangles would be a coin toss — so resume carries the
     * bar that says "carry on from where this stopped", the convention a media transport already uses.
     */
    public const PLAY = '<path d="M8 5.2v13.6L19 12Z"/>';

    public const PAUSE = '<path d="M9.5 5v14"/><path d="M14.5 5v14"/>';

    public const RESUME = '<path d="M6.5 5v14"/><path d="M10.5 6.2v11.6L19 12Z"/>';

    public const STOP = '<rect x="6.5" y="6.5" width="11" height="11" rx="1.6"/>';

    public static function svg(string $paths, string $label): string
    {
        return '<svg class="a2t-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths . '</svg><span class="a2t-sr">' . Html::encode($label) . '</span>';
    }
}
