<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use Codeception\Test\Unit;

use function file_get_contents;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ChatIndexTemplateTest extends Unit
{
    public function testAdminChatTemplateIsSingleThread(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Chat/Web/Index/template.php');

        assertStringContainsString('data-chat-root', $template);
        assertStringContainsString('chat__composer', $template);
        assertStringContainsString('Load older messages', $template);
        assertStringNotContainsString('Start conversation', $template);
        assertStringNotContainsString('Conversations', $template);
        assertStringNotContainsString('All conversations', $template);
    }

    public function testAgentChatTemplateIsSingleThread(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Agent/Web/Chat/template.php');

        assertStringContainsString('data-chat-root', $template);
        assertStringContainsString('chat__composer', $template);
        assertStringNotContainsString('Start conversation', $template);
    }

    /**
     * Both chat surfaces carry the inline edit form, retry form, and blocked-composer markup the JS and
     * server guards hang off — routed through the per-surface message routes.
     */
    public function testAdminChatTemplateHasEditAffordances(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Chat/Web/Index/template.php');

        assertStringContainsString('data-edit-form', $template);
        assertStringContainsString('data-edit-toggle', $template);
        assertStringContainsString('data-retry-form', $template);
        assertStringContainsString('data-composer-blocked', $template);
        assertStringContainsString('expected_edit_count', $template);
        assertStringContainsString('chat.message.edit', $template);
        assertStringContainsString('chat.message.regenerate', $template);
    }

    public function testAgentChatTemplateHasEditAffordances(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Agent/Web/Chat/template.php');

        assertStringContainsString('data-edit-form', $template);
        assertStringContainsString('data-retry-form', $template);
        assertStringContainsString('data-composer-blocked', $template);
        assertStringContainsString('agent.chat.message.edit', $template);
        assertStringContainsString('agent.chat.message.regenerate', $template);
    }

    public function testAdminJsHasNoWebsocketTransport(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.js');

        assertStringNotContainsString('WebSocket', $js);
        assertStringNotContainsString('EventSource', $js);
        assertStringNotContainsString('socket.io', $js);
        assertStringContainsString('data-load-older', $js);
        assertStringContainsString('textContent', $js);
    }

    /**
     * The edit/retry enhancement stays CSP-safe: delegated handlers, no inline handlers or eval.
     */
    public function testAdminJsHasDelegatedEditHandlers(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.js');

        assertStringContainsString('data-edit-toggle', $js);
        assertStringContainsString('data-edit-form', $js);
        assertStringContainsString('data-retry-form', $js);
        assertStringNotContainsString('onclick', $js);
        assertStringNotContainsString('eval(', $js);
    }
}
