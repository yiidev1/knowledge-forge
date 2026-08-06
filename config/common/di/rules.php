<?php

declare(strict_types=1);

use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Rules\Contract\RuleClassificationEventRepositoryInterface;
use App\Rules\Contract\RuleReadinessReaderInterface;
use App\Rules\Contract\RuleReportReaderInterface;
use App\Rules\Contract\RuleStoreLinkRepositoryInterface;
use App\Rules\Contract\StoreAliasRepositoryInterface;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Rules\Infrastructure\DbRuleClassificationEventRepository;
use App\Rules\Infrastructure\DbRuleReadinessReader;
use App\Rules\Infrastructure\DbRuleReportReader;
use App\Rules\Infrastructure\DbRuleStoreLinkRepository;
use App\Rules\Infrastructure\DbStoreAliasRepository;

// The Rules module. Repository/reader ports map to their MySQL adapters; the hasher, matcher, catalog/
// classification services, seeder, runner, sync handler and report action are autowired.
return [
    RuleCatalogRepositoryInterface::class => DbRuleCatalogRepository::class,
    RuleReportReaderInterface::class => DbRuleReportReader::class,
    RuleReadinessReaderInterface::class => DbRuleReadinessReader::class,
    StoreAliasRepositoryInterface::class => DbStoreAliasRepository::class,
    RuleStoreLinkRepositoryInterface::class => DbRuleStoreLinkRepository::class,
    RuleClassificationEventRepositoryInterface::class => DbRuleClassificationEventRepository::class,
];
