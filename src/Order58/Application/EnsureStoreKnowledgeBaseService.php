<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\KnowledgeBase\Application\SlugGenerator;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use DateTimeImmutable;
use RuntimeException;
use Yiisoft\Db\Exception\IntegrityException;

/**
 * Ensures exactly one knowledge base exists for an Order58 store, and keeps its source state current.
 *
 * A brand-new store's knowledge base is created `pending`; an inactive store is created with
 * `source_active = false` so the provisioning drainer defers its vector store until the store becomes
 * active. Knowledge bases are only ever created here (from a store) — never from a knowledge record.
 */
final readonly class EnsureStoreKnowledgeBaseService
{
    public const SOURCE = 'order58';

    public function __construct(
        private KnowledgeBaseSourceRepositoryInterface $repository,
        private SlugGenerator $slugGenerator,
    ) {}

    /**
     * @return int The mapped knowledge base id.
     */
    public function ensure(int $sourceStoreId, string $name, bool $active, DateTimeImmutable $now): int
    {
        $existingId = $this->repository->findIdBySource(self::SOURCE, $sourceStoreId);
        if ($existingId !== null) {
            $this->repository->updateSourceState($existingId, $name, $name, $active, $now);

            return $existingId;
        }

        $slug = $this->slugGenerator->generate($name);

        try {
            return $this->repository->createForSource(
                $name,
                $slug,
                self::SOURCE,
                $sourceStoreId,
                $name,
                $active,
                $now,
            );
        } catch (IntegrityException $e) {
            // A concurrent worker created it first — adopt the existing mapping rather than duplicating.
            $adopted = $this->repository->findIdBySource(self::SOURCE, $sourceStoreId);
            if ($adopted !== null) {
                $this->repository->updateSourceState($adopted, $name, $name, $active, $now);

                return $adopted;
            }

            throw new RuntimeException('Could not map store ' . $sourceStoreId . ' to a knowledge base.', 0, $e);
        }
    }
}
