<?php

declare(strict_types=1);

use App\KnowledgeBase\Domain\KnowledgeBaseProvisioningRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseProvisioningRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\KnowledgeBase\Infrastructure\DbRuleRepository;

// Only the repository interfaces need binding; services, the finder, the provisioning drainer and
// actions are autowired from their constructor types.
return [
    KnowledgeBaseRepositoryInterface::class => DbKnowledgeBaseRepository::class,
    RuleRepositoryInterface::class => DbRuleRepository::class,
    KnowledgeBaseProvisioningRepositoryInterface::class => DbKnowledgeBaseProvisioningRepository::class,
];
