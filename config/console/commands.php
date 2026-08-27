<?php

declare(strict_types=1);

use App\Console;

return [
    'hello' => Console\HelloCommand::class,
    'kf:health' => Console\HealthCommand::class,
    'kf:admin:create' => App\Auth\Console\CreateAdminCommand::class,
    'kf:openai:ping' => App\Ai\OpenAi\Console\OpenAiPingCommand::class,
    'kf:worker:run' => App\Worker\Console\RunWorkerCommand::class,
    // Deliberately NOT a drainer inside kf:worker:run: a transcription holds one core for ~94 seconds,
    // and running it in that loop would stall document processing and Order58 sync behind it. Separate
    // command, separate lock file, separate schedule.
    'kf:audio:worker' => App\AudioToText\Console\AudioTranscriptionWorkerCommand::class,
    'kf:documents:recover' => App\Worker\Console\RecoverDocumentsCommand::class,
    'kf:ai:reconcile' => App\Worker\Console\ReconcileCommand::class,
    'kf:order58:reconcile-active' => App\Order58\Console\ReconcileActiveStatusCommand::class,
    'kf:order58:schedule-agents' => App\Order58\Console\ScheduleAgentsSyncCommand::class,
    'kf:order58:schedule-knowledge' => App\Order58\Console\ScheduleKnowledgeSyncCommand::class,
    'kf:order58:schedule-rules' => App\Order58\Console\ScheduleRulesSyncCommand::class,
    'kf:rules:reconcile-global' => App\Rules\Console\ReconcileGlobalProjectionsCommand::class,
    'kf:rules:repair-lifecycle' => App\Rules\Console\RepairRuleLifecycleCommand::class,
    'kf:rules:retire-store-projections' => App\Rules\Console\RetireStoreRuleProjectionsCommand::class,
    'chat:thread-merge-report' => App\Chat\Console\ThreadMergeReportCommand::class,
    'chat:participant-backfill-report' => App\Chat\Console\ParticipantBackfillReportCommand::class,
];
