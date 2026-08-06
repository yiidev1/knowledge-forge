<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Infrastructure\DbGeneratedDocumentRepository;
use App\Document\Infrastructure\DbIndexedFileRepository;
use App\Document\Infrastructure\DbProcessingEventRepository;
use App\Document\Infrastructure\LocalDocumentStorage;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Order58\Application\SyncDocumentService;
use App\Order58\Infrastructure\DbOrder58StoreRepository;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Application\RuleClassificationRunner;
use App\Rules\Application\RuleClassificationService;
use App\Rules\Application\RuleDocumentRenderer;
use App\Rules\Application\RuleProjectionReconciler;
use App\Rules\Application\RuleStoreMatcher;
use App\Rules\Application\StoreAliasSeeder;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Rules\Infrastructure\DbRuleClassificationEventRepository;
use App\Rules\Infrastructure\DbRuleStoreLinkRepository;
use App\Rules\Infrastructure\DbStoreAliasRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Storage\StoragePaths;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Assembles the real (DB-backed) rules classification + materialization stack for integration tests, mirroring
 * how the DI container wires it in production.
 */
final class RulesTestFactory
{
    public static function reconciler(ConnectionInterface $connection, string $storageRoot): RuleProjectionReconciler
    {
        $clock = new SystemClock();
        $documents = new SyncDocumentService(
            new DbGeneratedDocumentRepository($connection),
            new LocalDocumentStorage(new StoragePaths($storageRoot, $storageRoot . '/worker.lock', $storageRoot . '/logs')),
            new DbIndexedFileRepository($connection, $clock),
            new DbProcessingEventRepository($connection, $clock),
            new SafeFilenameGenerator(),
        );

        return new RuleProjectionReconciler(
            new DbRuleCatalogRepository($connection),
            new DbRuleStoreLinkRepository($connection),
            new EnsureCommonRulesKnowledgeBaseService(new DbKnowledgeBaseRepository($connection, $clock)),
            new DbKnowledgeBaseSourceRepository($connection),
            $documents,
            new RuleDocumentRenderer(),
            new DbOrder58StoreRepository($connection),
        );
    }

    public static function classificationRunner(ConnectionInterface $connection, string $storageRoot): RuleClassificationRunner
    {
        $clock = new SystemClock();
        $catalog = new DbRuleCatalogRepository($connection);
        $links = new DbRuleStoreLinkRepository($connection);
        $stores = new DbOrder58StoreRepository($connection);
        $aliases = new DbStoreAliasRepository($connection);
        $knowledgeBases = new DbKnowledgeBaseRepository($connection, $clock);

        $documents = new SyncDocumentService(
            new DbGeneratedDocumentRepository($connection),
            new LocalDocumentStorage(new StoragePaths($storageRoot, $storageRoot . '/worker.lock', $storageRoot . '/logs')),
            new DbIndexedFileRepository($connection, $clock),
            new DbProcessingEventRepository($connection, $clock),
            new SafeFilenameGenerator(),
        );

        $reconciler = new RuleProjectionReconciler(
            $catalog,
            $links,
            new EnsureCommonRulesKnowledgeBaseService($knowledgeBases),
            new DbKnowledgeBaseSourceRepository($connection),
            $documents,
            new RuleDocumentRenderer(),
            $stores,
        );

        return new RuleClassificationRunner(
            new StoreAliasSeeder($stores, $aliases),
            $aliases,
            $stores,
            $catalog,
            new RuleClassificationService($catalog, $links, new DbRuleClassificationEventRepository($connection), new RuleStoreMatcher()),
            $reconciler,
        );
    }
}
