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
];
