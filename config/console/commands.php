<?php

declare(strict_types=1);

use App\Console;

return [
    'hello' => Console\HelloCommand::class,
    'kf:health' => Console\HealthCommand::class,
    'kf:admin:create' => App\Auth\Console\CreateAdminCommand::class,
    'kf:openai:ping' => App\Ai\OpenAi\Console\OpenAiPingCommand::class,
    'kf:worker:run' => App\Worker\Console\RunWorkerCommand::class,
    'kf:documents:recover' => App\Worker\Console\RecoverDocumentsCommand::class,
    'kf:ai:reconcile' => App\Worker\Console\ReconcileCommand::class,
    'kf:order58:reconcile-active' => App\Order58\Console\ReconcileActiveStatusCommand::class,
    'kf:order58:schedule-knowledge' => App\Order58\Console\ScheduleKnowledgeSyncCommand::class,
    'kf:order58:schedule-rules' => App\Order58\Console\ScheduleRulesSyncCommand::class,
    'kf:rules:reconcile-global' => App\Rules\Console\ReconcileGlobalProjectionsCommand::class,
    'chat:thread-merge-report' => App\Chat\Console\ThreadMergeReportCommand::class,
    'chat:participant-backfill-report' => App\Chat\Console\ParticipantBackfillReportCommand::class,
];
