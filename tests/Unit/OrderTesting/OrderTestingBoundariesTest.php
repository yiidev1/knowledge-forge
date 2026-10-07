<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\Order58\Application\Orders\OrderMapper;
use App\OrderTesting\Domain\InitiatorType;
use App\Shared\Order58\PaymentSecretRedactor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_map;
use function dirname;
use function file_get_contents;
use function implode;
use function json_encode;
use function is_dir;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The boundaries this feature promised not to cross.
 *
 * Every one of these was an explicit instruction rather than a design preference, which is exactly why
 * they are tests: an instruction nobody can check is an instruction that decays.
 */
final class OrderTestingBoundariesTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return iterable<string, string> relative path => source */
    private function phpFilesIn(string $directory): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = (string) $file->getRealPath();
                yield substr($path, strlen($this->root()) + 1) => (string) file_get_contents($path);
            }
        }
    }

    /**
     * The payment-secret list is stated twice; this is what stops the two drifting.
     *
     * Order Testing does not import the Order58 sync module for five strings, and the sync module's own
     * copy is a private method inside a file in the live sync path that there is no behavioural reason
     * to edit. So both exist — and a key added to one and not the other fails here rather than quietly
     * leaking from whichever copy was forgotten.
     */
    public function testTheTwoPaymentSecretListsAreIdentical(): void
    {
        $mapper = (new ReflectionClass(OrderMapper::class))->getConstants();

        self::assertArrayHasKey('PAYMENT_SECRETS', $mapper, 'OrderMapper no longer states the list');
        self::assertSame(
            $mapper['PAYMENT_SECRETS'],
            PaymentSecretRedactor::PAYMENT_SECRETS,
            'The orders mirror and Order Testing must strip exactly the same fields.',
        );
        self::assertSame($mapper['NESTED_JSON'], PaymentSecretRedactor::NESTED_JSON);
    }

    /** The CVV is the one that is prohibited rather than merely unwise. */
    public function testTheRedactorRemovesSecretsAtEveryDepthAndInsideNestedJsonStrings(): void
    {
        $raw = [
            'id' => 1,
            'cvv' => 'top',
            'data' => '{"cc":{"cvv":"nested","card_num":"4111"},"cvv":"sibling","keep":"yes"}',
            'deep' => ['deeper' => ['exp_date' => '12/29', 'keep' => 'yes']],
        ];

        $clean = (string) json_encode(PaymentSecretRedactor::redact($raw));

        self::assertStringNotContainsString('cvv', $clean);
        self::assertStringNotContainsString('4111', $clean);
        self::assertStringNotContainsString('exp_date', $clean);
        // What must survive: everything that is not on the list.
        self::assertStringContainsString('"keep":"yes"', $clean);
    }

    // ------------------------------------------------------------------ no agent, no operation role

    /** Only ADMIN exists. Agents and any operation role are explicitly out of scope for this phase. */
    public function testOnlyTheAdminRealmExists(): void
    {
        self::assertSame(['ADMIN'], array_map(
            static fn(InitiatorType $case): string => $case->value,
            InitiatorType::cases(),
        ));
    }

    /**
     * No migration creates anything for an operation role.
     *
     * Asserted over the migrations rather than over this feature's own files, because the instruction
     * was that none exists anywhere — including as a value sitting in a CHECK constraint "ready for
     * later", which would be exactly the sort of thing nobody would notice having been added.
     */
    public function testNoMigrationMentionsAnOperationRole(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src/Migration') as $relative => $source) {
            // The quoted VALUE, not the English word. `AiOperations`, "operation" in a docblock and
            // `integration_sync_runs` are all ordinary prose; what must not exist is a role literal a
            // row could be written with.
            if (preg_match('/[\'"]OPERATION[\'"]/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /** The Order Testing module names no agent class and no agent route. */
    public function testOrderTestingNamesNoAgentCode(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src/OrderTesting') as $relative => $source) {
            foreach (['App\\Agent', 'agent.login', 'agent.home', 'RequireAgentMiddleware'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = $relative . ' names ' . $forbidden;
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Module isolation, from this module's side.
     *
     * Order Testing owns its own tables and reads everyone else's by table name — the same arrangement
     * the audio module already uses for `order58_orders`. Naming another module's classes would make
     * removing either one a cross-module change.
     */
    public function testOrderTestingDependsOnNoOtherBusinessModule(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src/OrderTesting') as $relative => $source) {
            foreach (['App\\AudioToText', 'App\\Order58', 'App\\Chat', 'App\\Agent', 'App\\Rules'] as $module) {
                if (str_contains($source, $module . '\\')) {
                    $offenders[] = $relative . ' depends on ' . $module;
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Nothing anywhere points the importer at the live order directory.
     *
     * The guard in DemoOrderDirectory is the defence; this is the check that nobody has written the
     * live path into configuration, a fixture or a default where it would be one deleted guard away
     * from being read.
     */
    public function testNothingNamesTheLiveOrderDirectory(): void
    {
        $offenders = [];

        foreach (['/src', '/config'] as $tree) {
            foreach ($this->phpFilesIn($this->root() . $tree) as $relative => $source) {
                // Two files are allowed to say the name: the guard that refuses it, and the
                // environment SPEC comment that explains why the default is not it. Both are the rule
                // being documented rather than the path being used, and a test that forbade naming it
                // at all would force the warning to be deleted.
                $documentsTheRule = str_starts_with($relative, 'src/OrderTesting/Application/DemoOrderDirectory')
                    || $relative === 'src/Environment.php';

                if (str_contains($source, '/data/orders/mix_orders') && !$documentsTheRule) {
                    $offenders[] = $relative;
                }

                // Whatever the prose says, no CONFIGURED value may be it.
                if (preg_match('/[\'"]\/data\/orders\/mix_orders/', $source) === 1 && !$documentsTheRule) {
                    $offenders[] = $relative . ' configures the live path';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /** No scoring, anywhere in the feature, in this phase. */
    public function testNoScoringExistsYet(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src/OrderTesting') as $relative => $source) {
            // Identifiers, not English. The comparison page says in prose that it calculates no
            // weighting or pass mark, and a test that banned the words would ban saying so. What must
            // not exist is a property or method that produces a verdict.
            foreach (['$score', '->score', 'accuracyPercent', 'totalScore', 'passMark'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = $relative . ' contains ' . $forbidden;
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }
}
