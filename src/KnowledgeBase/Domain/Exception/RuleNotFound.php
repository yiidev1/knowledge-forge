<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * A rule does not exist within the given knowledge base. Scoped lookups (rule id + knowledge-base id)
 * raise this when the pair does not match, so a rule id cannot be used against another knowledge base.
 */
final class RuleNotFound
{
    public static function inKnowledgeBase(int $ruleId, int $knowledgeBaseId): NotFoundException
    {
        return new NotFoundException(
            'rule_not_found',
            sprintf('Rule #%d was not found in knowledge base #%d.', $ruleId, $knowledgeBaseId),
        );
    }
}
