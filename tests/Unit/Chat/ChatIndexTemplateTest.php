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

    public function testAdminJsHasNoWebsocketTransport(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.js');

        assertStringNotContainsString('WebSocket', $js);
        assertStringNotContainsString('EventSource', $js);
        assertStringNotContainsString('socket.io', $js);
        assertStringContainsString('data-load-older', $js);
        assertStringContainsString('textContent', $js);
    }
}
