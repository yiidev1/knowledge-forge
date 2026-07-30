<?php

declare(strict_types=1);

return [
    'yiisoft/yii-console' => [
        'commands' => require __DIR__ . '/commands.php',
    ],

    'yiisoft/db-migration' => [
        // `migrate:create` writes here and `migrate:up` reads from here. Pointing both at one namespace
        // means a migration can never be applied from a directory that was not reviewed.
        'newMigrationNamespace' => 'App\\Migration',
        'sourceNamespaces' => ['App\\Migration'],
        'newMigrationPath' => '',
        'sourcePaths' => [],
    ],
];
