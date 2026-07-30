<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Tests\Support\ConsoleTester;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

final class HealthCest
{
    public function commandIsRegistered(ConsoleTester $I): void
    {
        $I->runShellCommand('php yii list');
        $I->seeInShellOutput('kf:health');
    }

    public function reportsEachCheck(ConsoleTester $I): void
    {
        // Exits non-zero while the database is unconfigured, so failures are tolerated here.
        $I->runShellCommand('php yii kf:health', false);

        $I->seeInShellOutput('Knowledge Forge health');
        $I->seeInShellOutput('storage');
        $I->seeInShellOutput('database.config');
        $I->seeInShellOutput('database.connection');
        $I->seeInShellOutput('openai.config');
        $I->seeInShellOutput('Config fingerprint');
    }

    /**
     * The reason the fingerprint exists: an operator runs this as the deployment user and again as
     * www-data, and compares. Identical configuration must produce an identical digest.
     */
    public function fingerprintIsStableAcrossRuns(ConsoleTester $I): void
    {
        $I->runShellCommand('php yii kf:health --json', false);
        $first = $this->decode($I);

        $I->runShellCommand('php yii kf:health --json', false);
        $second = $this->decode($I);

        assertSame($first['config_fingerprint'], $second['config_fingerprint']);
    }

    public function fingerprintChangesWhenConfigurationDiffers(ConsoleTester $I): void
    {
        $I->runShellCommand('php yii kf:health --json', false);
        $baseline = $this->decode($I);

        $I->runShellCommand('DB_NAME=some_other_database php yii kf:health --json', false);
        $changed = $this->decode($I);

        assertNotSame($baseline['config_fingerprint'], $changed['config_fingerprint']);
    }

    public function jsonOutputIsMachineReadable(ConsoleTester $I): void
    {
        $I->runShellCommand('php yii kf:health --json', false);
        $report = $this->decode($I);

        assertArrayHasKey('status', $report);
        assertArrayHasKey('environment', $report);
        assertArrayHasKey('checks', $report);
        assertIsArray($report['checks']);
    }

    /**
     * Health output is pasted into tickets and chat. It names variables, never their values.
     */
    public function outputNeverContainsTheApiKey(ConsoleTester $I): void
    {
        $key = 'sk-testonly-must-never-appear-1234567890';

        $I->runShellCommand(
            sprintf('OPENAI_API_KEY=%s OPENAI_CHAT_MODEL=m OPENAI_VISION_MODEL=m php yii kf:health', $key),
            false,
        );

        assertStringNotContainsString($key, $I->grabShellOutput());
    }

    public function failsWhenDebugIsEnabledInProduction(ConsoleTester $I): void
    {
        $I->runShellCommand('APP_ENV=prod APP_DEBUG=true php yii kf:health --json', false);

        $report = $this->decode($I);
        $statuses = [];

        /** @var list<array{name: string, status: string}> $checks */
        $checks = $report['checks'];

        foreach ($checks as $check) {
            $statuses[$check['name']] = $check['status'];
        }

        assertSame('failure', $statuses['app.debug']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ConsoleTester $I): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($I->grabShellOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
