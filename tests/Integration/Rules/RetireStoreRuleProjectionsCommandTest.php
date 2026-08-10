<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Document\Domain\DocumentSourceType;
use App\Document\Infrastructure\DbGeneratedDocumentRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Rules\Console\RetireStoreRuleProjectionsCommand;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\RulesTestFactory;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Yiisoft\Db\Connection\ConnectionInterface;

use function hash;
use function sys_get_temp_dir;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The fleet retire command against real MySQL. --dry-run reports the scope without writing anything; the real run
 * retires only order58_rule_store documents (via the standard disable path), leaving the global rule corpus and
 * every non-store-rule document untouched. It never calls OpenAI (the worker performs the remote removal).
 */
final class RetireStoreRuleProjectionsCommandTest extends Unit
{
    private const STORE = 975000010;
    private const STORE_SLUG = 'zzretire-store';

    private ConnectionInterface $connection;
    private DbKnowledgeBaseSourceRepository $kbSources;
    private DbGeneratedDocumentRepository $documents;
    private DateTimeImmutable $now;
    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->kbSources = new DbKnowledgeBaseSourceRepository($this->connection);
        $this->documents = new DbGeneratedDocumentRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-08-10 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testDryRunReportsButChangesNothing(): void
    {
        $kbId = $this->seedStoreKb();
        $this->seedDoc($kbId, DocumentSourceType::Order58RuleStore, 'r1');
        $this->seedDoc($kbId, DocumentSourceType::Order58RuleStore, 'r2');
        $this->seedDoc($kbId, DocumentSourceType::Order58RuleGlobal, 'g1');

        $output = new BufferedOutput();
        $this->command()->run(new ArrayInput(['--dry-run' => true]), $output);

        $text = $output->fetch();
        assertStringContainsString('Dry run', $text);
        assertStringContainsString('2', $text, 'reports the two store-rule documents that would be retired');

        // Nothing was written: every document is still live.
        assertSame('queued', $this->docStatus($kbId, DocumentSourceType::Order58RuleStore, 'r1'));
        assertSame('queued', $this->docStatus($kbId, DocumentSourceType::Order58RuleStore, 'r2'));
        assertSame('queued', $this->docStatus($kbId, DocumentSourceType::Order58RuleGlobal, 'g1'));
    }

    public function testRealRunRetiresStoreRuleDocumentsAndLeavesGlobalUntouched(): void
    {
        $kbId = $this->seedStoreKb();
        $this->seedDoc($kbId, DocumentSourceType::Order58RuleStore, 'r1');
        $this->seedDoc($kbId, DocumentSourceType::Order58RuleGlobal, 'g1');

        $output = new BufferedOutput();
        $this->command()->run(new ArrayInput([]), $output);

        assertSame('deleted', $this->docStatus($kbId, DocumentSourceType::Order58RuleStore, 'r1'), 'the store-rule document is retired');
        assertSame('queued', $this->docStatus($kbId, DocumentSourceType::Order58RuleGlobal, 'g1'), 'the global rule corpus is untouched');
    }

    private function command(): RetireStoreRuleProjectionsCommand
    {
        return new RetireStoreRuleProjectionsCommand(
            $this->documents,
            RulesTestFactory::syncDocumentService($this->connection, sys_get_temp_dir() . '/kf_retire_cmd'),
            new SystemClock(),
        );
    }

    private function seedStoreKb(): int
    {
        return $this->kbSources->createForSource('ZZRETIRE Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZRETIRE Store', true, $this->now);
    }

    private function seedDoc(int $knowledgeBaseId, DocumentSourceType $type, string $ref): void
    {
        $ts = DbDateTime::format($this->now);
        $token = 'zzretire_' . $ref . '_' . ++$this->seq;
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $knowledgeBaseId,
            'original_filename' => 'rule.md',
            'stored_path' => 'kb/' . $knowledgeBaseId . '/' . $token . '.md',
            'storage_token' => $token,
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $token),
            'kind' => 'text',
            'source_type' => $type->value,
            'source_ref' => $ref,
            'source_sync_hash' => 'h',
            'status' => 'queued',
            'is_enabled' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function docStatus(int $knowledgeBaseId, DocumentSourceType $type, string $ref): ?string
    {
        $value = $this->connection->createQuery()
            ->select('status')
            ->from('{{%documents}}')
            ->where(['knowledge_base_id' => $knowledgeBaseId, 'source_type' => $type->value, 'source_ref' => $ref])
            ->scalar();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function cleanup(): void
    {
        $this->connection->createCommand(
            'DELETE [[d]] FROM {{%documents}} [[d]] JOIN {{%knowledge_bases}} [[k]] ON [[k]].[[id]] = [[d]].[[knowledge_base_id]]'
            . ' WHERE [[k]].[[slug]] = :s',
            [':s' => self::STORE_SLUG],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => self::STORE_SLUG]);
    }
}
