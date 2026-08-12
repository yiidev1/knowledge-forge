<?php

declare(strict_types=1);

use App\Agent\Web\Chat as AgentChat;
use App\Agent\Web\Home as AgentHome;
use App\Agent\Web\Login as AgentLogin;
use App\Agent\Web\Logout as AgentLogout;
use App\Agent\Web\Middleware\RequireAgentMiddleware;
use App\Agent\Web\RuleChat as AgentRuleChat;
use App\Agent\Web\Sources as AgentSources;
use App\Ai\Web\Usage;
use App\Auth\Web\Login;
use App\Auth\Web\Logout;
use App\Auth\Web\Middleware\RequireAdminMiddleware;
use App\Chat\Web as Chat;
use App\Chat\Web\RuleChat as AdminRuleChat;
use App\Document\Web as Doc;
use App\KnowledgeBase\Web as Kb;
use App\Order58\Web\Agents as Order58Agents;
use App\Order58\Web\DataManagement as Order58Data;
use App\Order58\Web\StoreChat as Order58StoreChat;
use App\Order58\Web\Stores as Order58Stores;
use App\Rules\Web\Detail as RulesDetail;
use App\Rules\Web\GlobalBase as RulesGlobalBase;
use App\Rules\Web\Readiness as RulesReadiness;
use App\Rules\Web\Report as RulesReport;
use App\Rules\Web\Review as RulesReview;
use App\Rules\Web\RulesList as RulesList;
use App\Shared\Web\Middleware\DomainExceptionMiddleware;
use App\Web\Dashboard;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

return [
    // Public authentication routes. No admin middleware here, or logging in would require being
    // logged in. CSRF still applies to the POST routes through the global middleware stack.
    Route::get('/login')
        ->action(Login\ShowAction::class)
        ->name('auth.login.show'),
    Route::post('/login')
        ->action(Login\AuthenticateAction::class)
        ->name('auth.login'),

    // Public Order58 agent authentication (a separate realm from the local admin login above). CSRF still
    // applies to the POST through the global middleware stack.
    Route::get('/agent/login')
        ->action(AgentLogin\ShowAction::class)
        ->name('agent.login.show'),
    Route::post('/agent/login')
        ->action(AgentLogin\AuthenticateAction::class)
        ->name('agent.login'),

    // Everything else requires an authenticated administrator. RequireAdminMiddleware re-loads the
    // account from the database on every request; DomainExceptionMiddleware turns a not-found domain
    // exception into a plain 404 instead of a 500.
    Group::create()
        ->middleware(RequireAdminMiddleware::class)
        ->middleware(DomainExceptionMiddleware::class)
        ->routes(
            Route::get('/')
                ->action(Dashboard\Action::class)
                ->name('dashboard'),
            Route::post('/logout')
                ->action(Logout\Action::class)
                ->name('auth.logout'),

            // Knowledge bases.
            Route::get('/knowledge-bases')
                ->action(Kb\Index\Action::class)
                ->name('kb.index'),
            Route::get('/knowledge-bases/create')
                ->action(Kb\Create\ShowAction::class)
                ->name('kb.create'),
            Route::post('/knowledge-bases')
                ->action(Kb\Create\StoreAction::class)
                ->name('kb.store'),
            Route::get('/knowledge-bases/{slug}')
                ->action(Kb\Show\Action::class)
                ->name('kb.show'),
            Route::get('/knowledge-bases/{slug}/edit')
                ->action(Kb\Edit\ShowAction::class)
                ->name('kb.edit'),
            Route::post('/knowledge-bases/{slug}')
                ->action(Kb\Edit\UpdateAction::class)
                ->name('kb.update'),
            Route::post('/knowledge-bases/{slug}/archive')
                ->action(Kb\Archive\Action::class)
                ->name('kb.archive'),
            Route::post('/knowledge-bases/{slug}/restore')
                ->action(Kb\Archive\Action::class)
                ->name('kb.restore'),
            Route::post('/knowledge-bases/{slug}/sync-order58-knowledge')
                ->action(Kb\SyncKnowledge\Action::class)
                ->name('kb.sync-order58-knowledge'),

            // Rules, nested under a knowledge base. reorder is declared before {ruleId} routes so the
            // literal segment wins over the parameter.
            Route::post('/knowledge-bases/{slug}/rules')
                ->action(Kb\Rules\StoreAction::class)
                ->name('kb.rules.store'),
            Route::post('/knowledge-bases/{slug}/rules/reorder')
                ->action(Kb\Rules\ReorderAction::class)
                ->name('kb.rules.reorder'),
            Route::post('/knowledge-bases/{slug}/rules/{ruleId:\d+}')
                ->action(Kb\Rules\UpdateAction::class)
                ->name('kb.rules.update'),
            Route::post('/knowledge-bases/{slug}/rules/{ruleId:\d+}/toggle')
                ->action(Kb\Rules\ToggleAction::class)
                ->name('kb.rules.toggle'),
            Route::post('/knowledge-bases/{slug}/rules/{ruleId:\d+}/delete')
                ->action(Kb\Rules\DeleteAction::class)
                ->name('kb.rules.delete'),

            // Documents, nested under a knowledge base.
            Route::post('/knowledge-bases/{slug}/documents')
                ->action(Doc\Upload\Action::class)
                ->name('kb.documents.upload'),
            Route::get('/knowledge-bases/{slug}/documents/{documentId:\d+}/view')
                ->action(Doc\View\Action::class)
                ->name('kb.documents.view'),
            Route::get('/knowledge-bases/{slug}/documents/{documentId:\d+}/download')
                ->action(Doc\Download\Action::class)
                ->name('kb.documents.download'),
            Route::post('/knowledge-bases/{slug}/documents/{documentId:\d+}/delete')
                ->action(Doc\Delete\Action::class)
                ->name('kb.documents.delete'),
            Route::post('/knowledge-bases/{slug}/documents/{documentId:\d+}/retry')
                ->action(Doc\Retry\Action::class)
                ->name('kb.documents.retry'),
            Route::post('/knowledge-bases/{slug}/documents/{documentId:\d+}/reindex')
                ->action(Doc\Reindex\Action::class)
                ->name('kb.documents.reindex'),
            Route::post('/knowledge-bases/{slug}/documents/{documentId:\d+}/process-now')
                ->action(Doc\ProcessNow\Action::class)
                ->name('kb.documents.process-now'),
            Route::post('/knowledge-bases/{slug}/documents/{documentId:\d+}/toggle')
                ->action(Doc\Toggle\Action::class)
                ->name('kb.documents.toggle'),
            Route::post('/knowledge-bases/{slug}/documents/{documentId:\d+}/reset-order58')
                ->action(Doc\ResetOrder58\Action::class)
                ->name('kb.documents.reset-order58'),

            // Manual text: a typed knowledge document. The edit form is nested under the document id; the
            // GET/POST pairs share a path but need distinct route names.
            Route::get('/knowledge-bases/{slug}/manual-text')
                ->action(Doc\ManualText\ShowAction::class)
                ->name('kb.manual-text.create'),
            Route::post('/knowledge-bases/{slug}/manual-text')
                ->action(Doc\ManualText\StoreAction::class)
                ->name('kb.manual-text'),
            Route::get('/knowledge-bases/{slug}/documents/{documentId:\d+}/edit')
                ->action(Doc\ManualText\EditAction::class)
                ->name('kb.documents.edit.show'),
            Route::post('/knowledge-bases/{slug}/documents/{documentId:\d+}/edit')
                ->action(Doc\ManualText\UpdateAction::class)
                ->name('kb.documents.edit'),

            // Chat: one persistent admin thread per knowledge base (slug identifies the thread).
            Route::get('/knowledge-bases/{slug}/chat')
                ->action(Chat\Index\Action::class)
                ->name('chat.index'),
            Route::post('/knowledge-bases/{slug}/chat')
                ->action(Chat\Start\Action::class)
                ->name('chat.start'),
            Route::get('/knowledge-bases/{slug}/chat/history')
                ->action(Chat\History\Action::class)
                ->name('chat.history'),
            // Read-only source transparency for this store chat: what knowledge / which rules it may use.
            // Declared before the {conversationId} routes; the digit constraint means there is no overlap.
            Route::get('/knowledge-bases/{slug}/chat/knowledge')
                ->action(Chat\Sources\KnowledgeAction::class)
                ->name('chat.sources.knowledge'),
            Route::get('/knowledge-bases/{slug}/chat/rules')
                ->action(Chat\Sources\StoreRulesAction::class)
                ->name('chat.sources.rules'),
            Route::get('/knowledge-bases/{slug}/chat/{conversationId:\d+}')
                ->action(Chat\Show\Action::class)
                ->name('chat.show'),
            Route::post('/knowledge-bases/{slug}/chat/{conversationId:\d+}')
                ->action(Chat\Ask\Action::class)
                ->name('chat.ask'),
            Route::post('/knowledge-bases/{slug}/chat/{conversationId:\d+}/messages/{messageId:\d+}/edit')
                ->action(Chat\EditMessage\Action::class)
                ->name('chat.message.edit'),
            Route::post('/knowledge-bases/{slug}/chat/{conversationId:\d+}/messages/{messageId:\d+}/regenerate')
                ->action(Chat\RegenerateMessage\Action::class)
                ->name('chat.message.regenerate'),

            // OpenAI usage and vector-store inventory. Read-only, and deliberately absent from the
            // sidebar and every other page — it is an operator diagnostic reached by direct URL, not a
            // feature. Being inside this group is what makes it admin-only; there is no second gate.
            Route::get('/admin/openai-usage')
                ->action(Usage\IndexAction::class)
                ->name('ai.usage.index'),
            Route::post('/admin/openai-usage/sync')
                ->action(Usage\SyncAction::class)
                ->name('ai.usage.sync'),

            // Order58 Data Management: the three independent primary sync buttons plus the health check
            // and the read-only agents mirror. Every state-changing route is POST + CSRF and only enqueues
            // work — the paginated API calls and OpenAI indexing happen in the worker.
            Route::get('/admin/order58')
                ->action(Order58Data\Action::class)
                ->name('order58.index'),
            Route::post('/admin/order58/sync')
                ->action(Order58Data\SyncAction::class)
                ->name('order58.sync'),
            Route::post('/admin/order58/check')
                ->action(Order58Data\CheckConnectionAction::class)
                ->name('order58.check'),
            Route::get('/admin/order58/agents')
                ->action(Order58Agents\Action::class)
                ->name('order58.agents'),
            // Read-only Order58 rules report: raw source mirror + deduplicated canonical catalog summary.
            Route::get('/admin/order58/rules')
                ->action(RulesReport\Action::class)
                ->name('order58.rules'),
            // Everyday operational readiness view of materialized rule documents (the Browse-rules destination).
            Route::get('/admin/order58/rules/readiness')
                ->action(RulesReadiness\Action::class)
                ->name('order58.rules.readiness'),
            // Hidden, URL-only diagnostic view of the hidden Global/Common Rules base (not linked from any nav).
            Route::get('/admin/order58/rules/global')
                ->action(RulesGlobalBase\Action::class)
                ->name('order58.rules.global'),
            // The detailed, filterable per-rule listing (advanced/diagnostic — reachable by direct URL only).
            Route::get('/admin/order58/rules/list')
                ->action(RulesList\Action::class)
                ->name('order58.rules.list'),
            // The per-rule review detail page.
            Route::get('/admin/order58/rules/{ruleId:\d+}')
                ->action(RulesDetail\Action::class)
                ->name('order58.rules.detail'),
            // Admin classification review — each decision reconciles the searchable projection immediately.
            Route::post('/admin/order58/rules/review')
                ->action(RulesReview\Action::class)
                ->name('order58.rules.review'),
            Route::post('/admin/order58/stores/{storeId:\d+}/sync-knowledge')
                ->action(Order58Data\StoreKnowledgeSyncAction::class)
                ->name('order58.store.knowledge'),
            Route::post('/admin/order58/stores/{storeId:\d+}/rebuild')
                ->action(Order58Data\StoreRebuildAction::class)
                ->name('order58.store.rebuild'),

            // Admin store directory: full-width, searchable, alphabetised, filterable grid of every mirrored
            // store. The literal /stores route is declared after the {storeId} action routes above, but the
            // digit-constrained parameter means there is no overlap either way.
            Route::get('/admin/order58/stores')
                ->action(Order58Stores\Action::class)
                ->name('order58.stores'),
            Route::post('/admin/order58/stores/{storeId:\d+}/agent-access')
                ->action(Order58Stores\ToggleAgentAccessAction::class)
                ->name('order58.store.agent-access'),

            // Store chat: an alphabetical picker of chat-ready stores that opens the admin chat for one.
            Route::get('/admin/order58/store-chat')
                ->action(Order58StoreChat\Action::class)
                ->name('order58.store-chat'),

            // Dedicated Admin Rule Chat against the hidden global-rules knowledge base (not store chat).
            Route::get('/admin/rule-chat')
                ->action(AdminRuleChat\Index\Action::class)
                ->name('admin.rule-chat.index'),
            Route::post('/admin/rule-chat')
                ->action(AdminRuleChat\Start\Action::class)
                ->name('admin.rule-chat.start'),
            Route::get('/admin/rule-chat/history')
                ->action(AdminRuleChat\History\Action::class)
                ->name('admin.rule-chat.history'),
            // Read-only: the indexed global rules this Rule Chat can actually search.
            Route::get('/admin/rule-chat/rules')
                ->action(Chat\Sources\RuleChatRulesAction::class)
                ->name('admin.rule-chat.sources.rules'),
            Route::get('/admin/rule-chat/{conversationId:\d+}')
                ->action(AdminRuleChat\Show\Action::class)
                ->name('admin.rule-chat.show'),
            Route::post('/admin/rule-chat/{conversationId:\d+}')
                ->action(AdminRuleChat\Ask\Action::class)
                ->name('admin.rule-chat.ask'),
            Route::post('/admin/rule-chat/{conversationId:\d+}/messages/{messageId:\d+}/edit')
                ->action(AdminRuleChat\EditMessage\Action::class)
                ->name('admin.rule-chat.message.edit'),
            Route::post('/admin/rule-chat/{conversationId:\d+}/messages/{messageId:\d+}/regenerate')
                ->action(AdminRuleChat\RegenerateMessage\Action::class)
                ->name('admin.rule-chat.message.regenerate'),
        ),

    // Order58 agents: a separate authenticated realm behind RequireAgentMiddleware. Agents can select any
    // active/ready store and chat with it; they never reach an admin route, and account_id plays no part.
    Group::create()
        ->middleware(RequireAgentMiddleware::class)
        ->middleware(DomainExceptionMiddleware::class)
        ->routes(
            Route::post('/agent/logout')
                ->action(AgentLogout\Action::class)
                ->name('agent.logout'),
            Route::get('/agent')
                ->action(AgentHome\Action::class)
                ->name('agent.home'),
            Route::get('/agent/rule-chat')
                ->action(AgentRuleChat\IndexAction::class)
                ->name('agent.rule-chat.index'),
            Route::post('/agent/rule-chat')
                ->action(AgentRuleChat\StartAction::class)
                ->name('agent.rule-chat.start'),
            Route::get('/agent/rule-chat/history')
                ->action(AgentRuleChat\HistoryAction::class)
                ->name('agent.rule-chat.history'),
            Route::get('/agent/rule-chat/rules')
                ->action(AgentSources\RuleChatRulesAction::class)
                ->name('agent.rule-chat.sources.rules'),
            Route::get('/agent/rule-chat/{conversationId:\d+}')
                ->action(AgentRuleChat\ShowAction::class)
                ->name('agent.rule-chat.show'),
            Route::post('/agent/rule-chat/{conversationId:\d+}')
                ->action(AgentRuleChat\AskAction::class)
                ->name('agent.rule-chat.ask'),
            Route::post('/agent/rule-chat/{conversationId:\d+}/messages/{messageId:\d+}/edit')
                ->action(AgentRuleChat\EditMessageAction::class)
                ->name('agent.rule-chat.message.edit'),
            Route::post('/agent/rule-chat/{conversationId:\d+}/messages/{messageId:\d+}/regenerate')
                ->action(AgentRuleChat\RegenerateMessageAction::class)
                ->name('agent.rule-chat.message.regenerate'),
            Route::get('/agent/stores/{slug}/chat')
                ->action(AgentChat\IndexAction::class)
                ->name('agent.chat.index'),
            Route::post('/agent/stores/{slug}/chat')
                ->action(AgentChat\StartAction::class)
                ->name('agent.chat.start'),
            Route::get('/agent/stores/{slug}/chat/history')
                ->action(AgentChat\HistoryAction::class)
                ->name('agent.chat.history'),
            // Same read-only source transparency as the admin store chat, behind the agent store resolver.
            Route::get('/agent/stores/{slug}/chat/knowledge')
                ->action(AgentSources\KnowledgeAction::class)
                ->name('agent.chat.sources.knowledge'),
            Route::get('/agent/stores/{slug}/chat/rules')
                ->action(AgentSources\StoreRulesAction::class)
                ->name('agent.chat.sources.rules'),
            Route::get('/agent/stores/{slug}/chat/{conversationId:\d+}')
                ->action(AgentChat\ShowAction::class)
                ->name('agent.chat.show'),
            Route::post('/agent/stores/{slug}/chat/{conversationId:\d+}')
                ->action(AgentChat\AskAction::class)
                ->name('agent.chat.ask'),
            Route::post('/agent/stores/{slug}/chat/{conversationId:\d+}/messages/{messageId:\d+}/edit')
                ->action(AgentChat\EditMessageAction::class)
                ->name('agent.chat.message.edit'),
            Route::post('/agent/stores/{slug}/chat/{conversationId:\d+}/messages/{messageId:\d+}/regenerate')
                ->action(AgentChat\RegenerateMessageAction::class)
                ->name('agent.chat.message.regenerate'),
        ),
];
