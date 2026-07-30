<?php

declare(strict_types=1);

use App\Ai\Web\Usage;
use App\Auth\Web\Login;
use App\Auth\Web\Logout;
use App\Auth\Web\Middleware\RequireAdminMiddleware;
use App\Chat\Web as Chat;
use App\Document\Web as Doc;
use App\KnowledgeBase\Web as Kb;
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

            // Chat, nested under a knowledge base.
            Route::get('/knowledge-bases/{slug}/chat')
                ->action(Chat\Index\Action::class)
                ->name('chat.index'),
            Route::post('/knowledge-bases/{slug}/chat')
                ->action(Chat\Start\Action::class)
                ->name('chat.start'),
            Route::get('/knowledge-bases/{slug}/chat/{conversationId:\d+}')
                ->action(Chat\Show\Action::class)
                ->name('chat.show'),
            Route::post('/knowledge-bases/{slug}/chat/{conversationId:\d+}')
                ->action(Chat\Ask\Action::class)
                ->name('chat.ask'),

            // OpenAI usage and vector-store inventory. Read-only, and deliberately absent from the
            // sidebar and every other page — it is an operator diagnostic reached by direct URL, not a
            // feature. Being inside this group is what makes it admin-only; there is no second gate.
            Route::get('/admin/openai-usage')
                ->action(Usage\IndexAction::class)
                ->name('ai.usage.index'),
            Route::post('/admin/openai-usage/sync')
                ->action(Usage\SyncAction::class)
                ->name('ai.usage.sync'),
        ),
];
