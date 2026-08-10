<?php

declare(strict_types=1);

use App\Chat\Application\ChatParams;
use App\Chat\Application\History\ConversationHistoryPolicyInterface;
use App\Chat\Application\History\RecentMessagesHistoryPolicy;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\MessageRevisionRepositoryInterface;
use App\Chat\Infrastructure\DbConversationRepository;
use App\Chat\Infrastructure\DbMessageRepository;
use App\Chat\Infrastructure\DbMessageRevisionRepository;

/** @var array $params */

// Repositories, the history policy and the resolved chat params. The ask service, instruction builder,
// citation resolver, grounding verifier, finders and actions are autowired from their constructor types.
return [
    ConversationRepositoryInterface::class => DbConversationRepository::class,
    MessageRepositoryInterface::class => DbMessageRepository::class,
    MessageRevisionRepositoryInterface::class => DbMessageRevisionRepository::class,

    ConversationHistoryPolicyInterface::class => [
        'class' => RecentMessagesHistoryPolicy::class,
        '__construct()' => [
            'messageLimit' => $params['app/chat']['historyMessageLimit'],
            'charLimit' => $params['app/chat']['historyCharLimit'],
        ],
    ],

    ChatParams::class => [
        '__construct()' => [
            'model' => $params['app/chat']['model'],
            'maxResults' => $params['app/chat']['maxResults'],
            'maxOutputTokens' => $params['app/chat']['maxOutputTokens'],
            'forceFileSearch' => $params['app/chat']['forceFileSearch'],
            'requireCitations' => $params['app/chat']['requireCitations'],
            'minCitationScore' => $params['app/chat']['minCitationScore'],
            'fallbackMessage' => $params['app/chat']['fallbackMessage'],
            'maxQuestionLength' => $params['app/chat']['maxQuestionLength'],
            'reasoningEffort' => $params['app/chat']['reasoningEffort'],
            'exhaustiveMaxResults' => $params['app/chat']['exhaustiveMaxResults'],
            'truncatedMessage' => $params['app/chat']['truncatedMessage'],
        ],
    ],
];
