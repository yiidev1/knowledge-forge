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
    // Separate from kf:audio:worker for the mirror-image reason. That one processes at most ONE job per
    // tick, so generating speech inside it would spend a transcription slot and halve the rate at which
    // recordings turn into text. The two do not contend either — transcription is CPU-bound, this waits
    // on HTTPS — so they run side by side, each with its own lock file and schedule.
    'kf:audio:tts-worker' => App\AudioToText\Console\AudioTtsWorkerCommand::class,
    'kf:documents:recover' => App\Worker\Console\RecoverDocumentsCommand::class,
    'kf:ai:reconcile' => App\Worker\Console\ReconcileCommand::class,
    'kf:order58:reconcile-active' => App\Order58\Console\ReconcileActiveStatusCommand::class,
    'kf:order58:schedule-agents' => App\Order58\Console\ScheduleAgentsSyncCommand::class,
    'kf:order58:schedule-knowledge' => App\Order58\Console\ScheduleKnowledgeSyncCommand::class,
    'kf:order58:schedule-rules' => App\Order58\Console\ScheduleRulesSyncCommand::class,
    // Its own command rather than a drainer inside kf:worker:run, for the reason the transcription
    // worker is: it downloads megabytes over a third-party network and then waits on the audio
    // pipeline, and running that in the shared worker would stall documents and sync behind it.
    'kf:order58:import-recordings' => App\Order58\Console\ImportRecordingsCommand::class,
    'kf:rules:reconcile-global' => App\Rules\Console\ReconcileGlobalProjectionsCommand::class,
    'kf:rules:repair-lifecycle' => App\Rules\Console\RepairRuleLifecycleCommand::class,
    'kf:rules:retire-store-projections' => App\Rules\Console\RetireStoreRuleProjectionsCommand::class,
    'chat:thread-merge-report' => App\Chat\Console\ThreadMergeReportCommand::class,
    'chat:participant-backfill-report' => App\Chat\Console\ParticipantBackfillReportCommand::class,
];
