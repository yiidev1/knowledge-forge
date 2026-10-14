<?php

declare(strict_types=1);

use App\Practice\Application\AssignTopicService;
use App\Practice\Application\TopicInputValidator;
use App\Practice\Application\TopicService;
use App\Practice\Domain\SourceOrderExistenceInterface;
use App\Practice\Domain\TopicAssignmentRepositoryInterface;
use App\Practice\Domain\TopicRepositoryInterface;
use App\Practice\Infrastructure\DbSourceOrderExistence;
use App\Practice\Infrastructure\DbTopicAssignmentRepository;
use App\Practice\Infrastructure\DbTopicRepository;

/**
 * Practice Orders: training topics and the orders they are assigned to.
 *
 * Nothing here is shared with Order Testing on purpose — this module owns the topic tables and reads
 * one fact from the orders mirror through a port of its own, so Order Testing is unaware of it and
 * keeps working exactly as before.
 */
return [
    TopicRepositoryInterface::class => DbTopicRepository::class,
    TopicAssignmentRepositoryInterface::class => DbTopicAssignmentRepository::class,

    // Reads `order58_orders` by table name; see the class docblock for why it is not a call into the
    // module that owns that table.
    SourceOrderExistenceInterface::class => DbSourceOrderExistence::class,

    TopicInputValidator::class => TopicInputValidator::class,
    TopicService::class => TopicService::class,
    AssignTopicService::class => AssignTopicService::class,
];
