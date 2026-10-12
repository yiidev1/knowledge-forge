<?php

declare(strict_types=1);

namespace App\Shared\Console;

use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Shared\Infrastructure\Db\DatabaseTargetRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Says which database this project is actually talking to, and whether a schema change is allowed.
 *
 * Run it **before** `migrate:up`, every time. Configuration cannot answer this question reliably — a
 * `DB_NAME` shell override, a `.env` edit and a server-level variable all claim to decide the target
 * and the winner depends on load order. This asks the server instead: `DATABASE()` is the schema the
 * connection is really using.
 *
 * Reads nothing but server metadata and changes nothing.
 */
#[AsCommand(
    name: 'kf:db:where',
    description: 'Show which database is actually connected, and whether schema changes are permitted.',
)]
final class WhereAmIDbCommand extends Command
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = DatabaseTarget::resolve($this->connection);

        $io->definitionList(
            ['Schema (from the SERVER)' => $target->schema],
            ['Server hostname' => $target->serverHost],
            ['Connected as' => $target->user],
            ['Transport' => $target->socket !== '' ? 'unix socket ' . $target->socket : 'tcp ' . $target->configuredHost],
            ['APP_ENV' => $target->appEnv],
            ['Provably local' => $target->isProvablyLocal() ? 'yes' : 'NO'],
        );

        try {
            $target->assertLocalForSchemaChange();
        } catch (DatabaseTargetRefused $e) {
            $io->error($e->getMessage());

            return ExitCode::CONFIG;
        }

        $io->success('Schema changes are permitted against this target.');

        return ExitCode::OK;
    }
}
