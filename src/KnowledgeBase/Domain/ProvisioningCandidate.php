<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

/**
 * The minimal view of a knowledge base the provisioning worker needs: enough to name its vector store
 * and to decide when to give up. Kept separate from {@see KnowledgeBase} so the read model does not
 * grow worker-only bookkeeping like the attempt count.
 */
final readonly class ProvisioningCandidate
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public int $attempts,
    ) {}
}
