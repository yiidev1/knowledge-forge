<?php

declare(strict_types=1);

namespace App\Auth\Console;

use App\Auth\Domain\AdminUserRepositoryInterface;
use App\Auth\Domain\PasswordHasherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

use function preg_match;
use function strlen;

/**
 * Creates an administrator account from the command line.
 *
 * The password is generated and printed once, never echoed back from an argument, so it does not end
 * up in the shell history or the process list. Passing a password on the command line is intentionally
 * not supported for that reason. An interactive prompt is offered when a TTY is available; otherwise a
 * strong password is generated.
 */
#[AsCommand(
    name: 'kf:admin:create',
    description: 'Creates an administrator account.',
)]
final class CreateAdminCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly AdminUserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Administrator username', 'admin')
            ->addOption(
                'generate-password',
                null,
                InputOption::VALUE_NONE,
                'Always generate a random password instead of prompting, even on a TTY.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $username */
        $username = $input->getArgument('username');
        $username = trim($username);

        if (!$this->isValidUsername($username)) {
            $io->error('Username must be 3–64 characters: letters, digits, dot, dash or underscore.');

            return ExitCode::DATAERR;
        }

        try {
            if ($this->users->usernameExists($username)) {
                $io->error(sprintf('An administrator named "%s" already exists.', $username));

                return ExitCode::DATAERR;
            }

            [$password, $wasGenerated] = $this->resolvePassword($io, $input);
            $this->users->create($username, $this->hasher->hash($password));
        } catch (Throwable $e) {
            // Surface the class and message but not a full trace: this runs at a shell where a stack
            // dump adds noise and could echo a connection string.
            $io->error('Could not create the administrator: ' . $e->getMessage());

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $io->success(sprintf('Administrator "%s" created.', $username));

        if ($wasGenerated) {
            // Printed exactly once. It is not stored anywhere in plaintext and cannot be recovered.
            $io->writeln('  Generated password (shown once — store it now):');
            $io->writeln('  <info>' . $password . '</info>');
            $io->newLine();
        }

        return ExitCode::OK;
    }

    /**
     * @return array{0: string, 1: bool} The password and whether it was generated (versus entered).
     */
    private function resolvePassword(SymfonyStyle $io, InputInterface $input): array
    {
        $generate = $input->getOption('generate-password') === true;

        // Prompt only when we have an interactive terminal and were not told to generate. A hidden
        // prompt keeps the password off the screen and out of shell history.
        if (!$generate && $input->isInteractive()) {
            $password = (string) $io->askHidden(
                'Password (leave blank to generate a strong one)',
                static fn(?string $value): ?string => $value,
            );

            if ($password === '') {
                return [$this->generatePassword(), true];
            }

            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $io->warning(sprintf('Password shorter than %d characters; generating a strong one instead.', self::MIN_PASSWORD_LENGTH));

                return [$this->generatePassword(), true];
            }

            return [$password, false];
        }

        return [$this->generatePassword(), true];
    }

    /**
     * A 24-character URL-safe password from a cryptographic source (~142 bits of entropy).
     */
    private function generatePassword(): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='), 0, 24);
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) === 1;
    }
}
