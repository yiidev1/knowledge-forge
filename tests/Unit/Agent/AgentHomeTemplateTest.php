<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use Codeception\Test\Unit;

use function file_get_contents;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The agent store directory is a separate realm: a store card there must offer only "Open chat", never any
 * administrator capability. This guards against an admin action (manage KB, sync, rebuild, agent-access
 * toggle, store login) leaking into the agent template — the agent selects a store to chat with, nothing more.
 */
final class AgentHomeTemplateTest extends Unit
{
    private string $template;

    protected function _before(): void
    {
        $this->template = (string) file_get_contents(
            __DIR__ . '/../../../src/Agent/Web/Home/template.php',
        );
    }

    public function testAgentCardsOfferOpenChat(): void
    {
        assertStringContainsString('Open chat', $this->template);
        assertStringContainsString('agent.chat.index', $this->template);
    }

    public function testAgentCardsExposeNoAdministratorActions(): void
    {
        foreach (['Manage KB', 'Sync knowledge', 'Rebuild', 'agent-access', 'Login', 'Create Knowledge', 'Create Case Study'] as $forbiddenLabel) {
            assertStringNotContainsString($forbiddenLabel, $this->template, $forbiddenLabel . ' must not appear in the agent realm');
        }

        // No admin route names either — the agent template links only to agent chat.
        foreach (['order58.store', 'kb.show', 'kb.documents', 'order58.index'] as $adminRoute) {
            assertStringNotContainsString($adminRoute, $this->template, $adminRoute . ' is an admin route and must not be linked from the agent realm');
        }
    }
}
