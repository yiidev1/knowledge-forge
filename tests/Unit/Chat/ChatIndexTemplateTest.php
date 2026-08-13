<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use Codeception\Test\Unit;

use function file_get_contents;
use function substr;
use function strpos;
use function array_keys;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ChatIndexTemplateTest extends Unit
{
    /**
     * The four templates that are actually rendered, with the source route each one builds its chips from.
     * `Chat/Web/Show/template.php` and `Agent/Web/Chat/show.php` are deliberately absent: both Show actions
     * redirect, so those files are dead.
     *
     * @var array<string, string>
     */
    private const LIVE_TEMPLATES = [
        'src/Chat/Web/Index/template.php' => 'chat.message.source',
        'src/Chat/Web/RuleChat/Index/template.php' => 'admin.rule-chat.message.source',
        'src/Agent/Web/Chat/template.php' => 'agent.chat.message.source',
        'src/Agent/Web/RuleChat/template.php' => 'agent.rule-chat.message.source',
    ];

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

    /**
     * All four live surfaces ask their question through a single-line text input. A textarea would bring
     * back the Shift+Enter affordance the hint used to explain, and the hint is gone.
     */
    public function testEveryChatComposerIsASingleLineInput(): void
    {
        foreach (array_keys(self::LIVE_TEMPLATES) as $path) {
            $template = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);

            assertStringContainsString('type="text" id="chat-question" name="question"', $template);
            assertStringNotContainsString('<textarea class="field__control chat__input" id="chat-question"', $template);
            assertStringNotContainsString('Shift+Enter', $template);
            assertStringNotContainsString('chat__hint', $template);
        }
    }

    /**
     * A source chip is a button carrying a URL the SERVER generated, with the conversation and message bound
     * into it. If a template ever printed a bare document id for the client to assemble, this fails.
     */
    public function testSourceChipsCarryAServerGeneratedUrl(): void
    {
        foreach (self::LIVE_TEMPLATES as $path => $route) {
            $template = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);

            assertStringContainsString('data-source-url', $template);
            assertStringContainsString($route, $template);
            assertStringContainsString('\'messageId\' => $message->id', $template);
            assertStringContainsString('ChatPartials::sourceModal()', $template);
        }
    }

    /**
     * The dialog leaks nothing by itself: it is an empty shell filled in from the endpoint's reply.
     */
    public function testSourceModalMarkupCarriesNoIdentifiers(): void
    {
        $partial = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Chat/Web/_partial/source-modal.php');

        assertStringContainsString('data-source-modal', $partial);
        assertStringNotContainsString('documentId', $partial);
        assertStringNotContainsString('file_id', $partial);
        assertStringNotContainsString('vector', $partial);
    }

    /**
     * The modal is fetched and painted with delegated handlers and textContent only — no innerHTML, so a
     * document's own text can never become markup, and nothing needs 'unsafe-inline'.
     */
    public function testSourceModalJsIsCspSafe(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.js');

        assertStringContainsString('data-source-url', $js);
        assertStringContainsString('data-source-modal', $js);

        // Scoped to the modal handler: `innerHTML` is used elsewhere for the answer's own rendered
        // markdown, which the server produced. A source's text is a document body and must never be
        // treated as markup, so this block has to stay free of it.
        $start = strpos($js, '---- Source detail dialog');
        $end = strpos($js, '---- Scroll behaviour', (int) $start);
        $modalJs = substr($js, (int) $start, (int) $end - (int) $start);

        // The property access, not the word — the block's own comment mentions innerHTML to explain why it
        // is absent.
        assertStringContainsString('textContent', $modalJs);
        assertStringNotContainsString('.innerHTML', $modalJs);
        assertStringNotContainsString('insertAdjacentHTML', $modalJs);
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
