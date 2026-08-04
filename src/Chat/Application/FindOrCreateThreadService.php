<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use RuntimeException;
use Yiisoft\Db\Exception\IntegrityException;

/**
 * Resolves the single canonical conversation for a knowledge base + typed participant.
 *
 * Creation is for POST only. Concurrent inserts rely on ux_conversations_kb_participant_typed;
 * a loser re-selects the winning row and does not surface the race to the user.
 */
final readonly class FindOrCreateThreadService
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private ClockInterface $clock,
    ) {}

    public function find(int $knowledgeBaseId, ChatParticipant $participant): ?Conversation
    {
        return $this->conversations->findThread($knowledgeBaseId, $participant);
    }

    public function findOrCreate(int $knowledgeBaseId, ChatParticipant $participant, string $title): Conversation
    {
        $existing = $this->conversations->findThread($knowledgeBaseId, $participant);
        if ($existing instanceof Conversation) {
            return $existing;
        }

        try {
            $this->conversations->createThread(
                $knowledgeBaseId,
                $participant,
                $title,
                $this->clock->now(),
            );
        } catch (IntegrityException $e) {
            $race = $this->conversations->findThread($knowledgeBaseId, $participant);
            if ($race instanceof Conversation) {
                return $race;
            }

            throw new RuntimeException('Conversation uniqueness conflict without a visible row.', 0, $e);
        }

        $created = $this->conversations->findThread($knowledgeBaseId, $participant);
        if ($created instanceof Conversation) {
            return $created;
        }

        throw new RuntimeException('Failed to resolve canonical conversation after create.');
    }
}
