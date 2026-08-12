<?php

declare(strict_types=1);

namespace App\Chat\Web\Sources;

/**
 * Absolute filesystem paths to the source-transparency templates, which the admin and agent actions share.
 *
 * These must be absolute. {@see \Yiisoft\Yii\View\Renderer\WebViewRenderer} resolves a relative view name
 * against its configured view path and throws "The view path is not set." when an action sets only a layout —
 * and it does NOT expand a `@src/...` alias in a view name, so an alias string fails the same way. Returning
 * `__DIR__`-anchored paths from the templates' own directory removes both traps, and keeps the agent actions
 * from having to know where the Chat module lives.
 */
final class SourceViews
{
    /** "Knowledge available to this chat". */
    public static function knowledge(): string
    {
        return __DIR__ . '/knowledge';
    }

    /** "Rules available to this chat" for a store chat. */
    public static function storeRules(): string
    {
        return __DIR__ . '/store-rules';
    }

    /** "Rules available to this chat" for Rule Chat. */
    public static function ruleChatRules(): string
    {
        return __DIR__ . '/rule-chat-rules';
    }
}
