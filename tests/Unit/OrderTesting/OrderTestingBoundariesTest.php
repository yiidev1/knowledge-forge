<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\Order58\Application\Orders\OrderMapper;
use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Domain\InitiatorType;
use App\Shared\Order58\PaymentSecretRedactor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_map;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
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
    /** The one migration permitted to introduce the OPERATION initiator literal. */
    /**
     * The migrations allowed to contain a realm literal — one per realm admitted, and no others.
     *
     * The AGENT migration restates the full list in its CHECK, so `'OPERATION'` appears in it too.
     * That is why this is a list rather than a single name.
     */
    private const INITIATOR_MIGRATIONS = [
        'M261012120000AllowOperationInitiator',
        'M261014180000AllowAgentInitiator',
    ];

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

    // ------------------------------------------------------------------ the realms, and only those

    /**
     * ADMIN, OPERATION and AGENT — the whole list, and no fourth.
     *
     * **This case was amended on purpose when the practice surface opened to agents**, and the
     * amendment is the point: each realm was added with its own migration widening the CHECK to match,
     * and each had to change this line to land. What the guard still catches is a realm appearing
     * without that deliberate step — the failure mode it was written for, since agents authenticate
     * through the same login page as operation users and a fourth case added by accident would hand a
     * workflow to accounts nobody reviewed.
     *
     * Agents reach Order Testing's *data* through the agent practice surface only; this module still
     * mounts no agent route of its own, which `testOrderTestingNamesNoAgentCode` below pins separately.
     *
     * Asserted as an exact list rather than a `contains`, so adding any realm fails until somebody
     * changes this line on purpose.
     */
    public function testOnlyTheThreeApprovedRealmsExist(): void
    {
        self::assertSame(['ADMIN', 'OPERATION', 'AGENT'], array_map(
            static fn(InitiatorType $case): string => $case->value,
            InitiatorType::cases(),
        ));
    }

    /**
     * The operation role literal appears in **exactly one** migration, and that one is the approved one.
     *
     * This used to assert it appeared nowhere. The role now exists, so the guard narrows rather than
     * disappears: what it still catches is the role being quietly admitted somewhere *else* — a second
     * CHECK constraint "ready for later" on a table nobody reviewed, which is precisely the sort of
     * thing that goes unnoticed. The widening is allowed in one named file and nowhere else.
     */
    public function testOnlyTheApprovedMigrationsMentionARealmRole(): void
    {
        $allowed = array_map(
            static fn(string $name): string => 'src/Migration/' . $name . '.php',
            self::INITIATOR_MIGRATIONS,
        );
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src/Migration') as $relative => $source) {
            // The quoted VALUE, not the English word. `AiOperations`, "operation" in a docblock and
            // `integration_sync_runs` are all ordinary prose; what must not exist is a role literal a
            // row could be written with.
            if (preg_match('/[\'"](OPERATION|AGENT)[\'"]/', $source) !== 1) {
                continue;
            }

            if (!in_array($relative, $allowed, true)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));

        foreach ($allowed as $file) {
            self::assertFileExists(
                $this->root() . '/' . $file,
                'An approved migration is missing; the allowance above would then hide a real regression.',
            );
        }
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

    /**
     * Scoring exists now, and these are the terms it was allowed on.
     *
     * The guard this replaces forbade scoring outright, for a stated reason: a number that adds up a
     * comparison is a business decision, and nobody had taken it. It has now been taken and specified,
     * so the test's job changes from "none of this exists" to "it exists in exactly one place, derived
     * from what the page shows, and it is not a verdict". Each clause below is one of the conditions
     * the specification set.
     */
    public function testScoringLivesInOneDerivedPlaceAndIsNotAVerdict(): void
    {
        // A score is a measurement of two documents' agreement. The moment it has a pass mark it is a
        // judgement about a person, which is a different feature with different consequences and was
        // not what was approved.
        /*
         * `isPerfect` is deliberately NOT in this list, and the distinction is the whole point.
         *
         * Every word here names a threshold somebody chose: a pass mark is a line drawn across other
         * people's work. "Perfect" draws no line — it is the statement that two documents are
         * identical, which is a property of the comparison itself and would be the same number
         * whoever read it. It decides how a card is presented and nothing about a person.
         */
        $verdicts = ['passMark', 'accuracyPercent', 'totalScore', 'isPass', 'grade'];

        /*
         * Where a score may be named at all.
         *
         * The rule is **one opinion**, not one file: there is exactly one service that decides what
         * two documents are worth, and everything else either hands it the documents or prints what
         * it said. A file that computed a score of its own would be a second opinion about the same
         * comparison, which is what this guard exists to prevent.
         *
         * The Practice Attempts entries below were added when that page shipped. They carry the
         * score — the history builder asks the same `ComparisonScorer` for it, the outcome holds the
         * `ComparisonScore` it returned, and the page prints it — and none of them computes one.
         */
        $mayScore = [
            'src/OrderTesting/Application/Comparison/ComparisonScorer.php',
            'src/OrderTesting/Domain/Comparison/ComparisonScore.php',
            'src/OrderTesting/Domain/Comparison/SectionScore.php',
            'src/OrderTesting/Web/Compare/Action.php',
            'src/OrderTesting/Web/Compare/template.php',
            // Practice Attempts: carries the score from the one service above, never its own.
            'src/OrderTesting/Application/Practice/PracticeHistoryBuilder.php',
            'src/OrderTesting/Domain/Practice/DemoOutcome.php',
            'src/OrderTesting/Domain/Practice/PracticeTry.php',
            'src/OrderTesting/Domain/Practice/AttemptGroup.php',
            'src/OrderTesting/Web/PracticeAttempts/Action.php',
            'src/OrderTesting/Web/PracticeAttempts/template.php',
            /*
             * Practice Results, added when the grouped cards became a paged table.
             *
             * `PractitionerScorer` is the entry that most deserves a second look, because its name
             * says it scores. It does not. It asks the one `ComparisonScorer` above for a score per
             * demo order and then CHOOSES among the answers — which is highest, which is newest, and
             * which it could not afford to compute. Choosing between numbers somebody else produced
             * is not a second opinion about them; computing one would be, and it does not.
             *
             * The rest carry a score without touching it: the history service hands documents to the
             * same scorer, the two value objects hold what came back, and the three templates print
             * it. None of them adds, weights or thresholds anything.
             */
            'src/OrderTesting/Application/Practice/PractitionerScorer.php',
            'src/OrderTesting/Application/Practice/PractitionerScores.php',
            'src/OrderTesting/Application/Practice/PractitionerHistory.php',
            'src/OrderTesting/Application/Practice/PractitionerTry.php',
            'src/OrderTesting/Web/PracticeAttempts/own.php',
            'src/OrderTesting/Web/PractitionerTries/template.php',
        ];

        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src/OrderTesting') as $relative => $source) {
            foreach ($verdicts as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = $relative . ' contains ' . $forbidden;
                }
            }

            if (in_array($relative, $mayScore, true)) {
                continue;
            }

            // Identifiers, not English: several files explain in prose what the score is computed
            // from, and a test that banned the words would ban explaining it. `ComparisonScorer` is
            // likewise allowed to be pointed at — what must not spread is the score itself, so the
            // class names are matched on a word boundary rather than as substrings.
            $forbidden = [
                '/\$score\b/' => '$score',
                '/->score\(/' => '->score()',
                '/\bComparisonScore(?![A-Za-z])/' => 'ComparisonScore',
                '/\bSectionScore(?![A-Za-z])/' => 'SectionScore',
            ];

            foreach ($forbidden as $pattern => $name) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = $relative . ' names a score (' . $name . ')';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * One table of weights, in the service that owns it.
     *
     * The page shows how the overall was made, and it would have been easy to restate the weights in
     * the template to do that. Then two numbers would claim to be the same weighting and a change to
     * one would quietly contradict the other, so the sections carry their own weight instead and the
     * template is only a reader of it.
     */
    public function testTheWeightingIsDeclaredOnlyInTheScorer(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src') as $relative => $source) {
            if ($relative === 'src/OrderTesting/Application/Comparison/ComparisonScorer.php') {
                continue;
            }

            foreach (['SECTION_WEIGHTS', 'FACET_WEIGHTS', 'FIELD_WEIGHTS'] as $table) {
                if (str_contains($source, $table)) {
                    $offenders[] = $relative . ' declares ' . $table;
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * The scorer can only look at the comparison it is handed.
     *
     * No repository, no clock, no HTTP client — so the same original and demo produce the same score in
     * every realm, on every render, and a score can never depend on who is looking at it. This is
     * asserted on the constructor rather than trusted to review, because a dependency is one parameter
     * away and the property it protects is the one the whole feature rests on.
     */
    public function testTheScorerDependsOnNothing(): void
    {
        $constructor = (new ReflectionClass(ComparisonScorer::class))->getConstructor();

        self::assertNull($constructor, 'ComparisonScorer must take no dependencies.');
    }

    /**
     * A score is derived, never stored.
     *
     * Both sides of a comparison change — items resync, a demo order is re-imported — so a stored score
     * is a claim about a comparison that no longer exists. There is therefore no column and no table to
     * keep in step, and this test is what stops one appearing by convenience later.
     */
    public function testNoScoreIsPersisted(): void
    {
        $offenders = [];

        foreach (['/src/OrderTesting', '/src/Migration'] as $tree) {
            foreach ($this->phpFilesIn($this->root() . $tree) as $relative => $source) {
                // An order-testing score column, by any of the names one would be given. Chat answer
                // scores are a different feature with its own tables, which is why this is anchored on
                // the names this feature would use rather than on the word alone.
                if (preg_match('/order_testing_[a-z_]*score|match_score|overall_score|score_percent/i', $source) === 1) {
                    $offenders[] = $relative . ' persists a score';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }
}
