<?php

declare(strict_types=1);

namespace App\Reports\Domain;

/**
 * Which chat surface a row belongs to.
 *
 * Decided from `knowledge_bases.purpose`, never from `messages.answer_source`. A live row proves why: an
 * answer carrying `answer_source = 'global_rule'` exists inside a store-purpose knowledge base, left over
 * from before Store and Rule chat were separated. The knowledge base's purpose is the conversation's actual
 * surface; the answer source only describes one reply.
 */
enum ChatTypeFilter: string
{
    case All = 'all';
    case Store = 'store';
    case Rule = 'rule';

    public static function fromRequest(?string $value): self
    {
        return $value === null ? self::All : (self::tryFrom($value) ?? self::All);
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All chats',
            self::Store => 'Store Knowledge',
            self::Rule => 'Rule Chat',
        };
    }
}
