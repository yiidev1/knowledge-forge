<?php

declare(strict_types=1);

namespace App\Chat\Domain;

/**
 * The data needed to append a message. A small write model so the repository's create signature stays
 * stable as the {@see Message} entity grows read-only accessors.
 */
final readonly class NewMessage
{
    /**
     * @param list<ResolvedCitation>            $citations
     * @param array{input_tokens: int, output_tokens: int, total_tokens: int}|null $usage
     */
    public function __construct(
        public int $conversationId,
        public MessageRole $role,
        public string $content,
        public array $citations = [],
        public ?array $usage = null,
        public bool $isGrounded = false,
        public ?string $retrievalStatus = null,
        public ?string $providerResponseId = null,
        public ?string $model = null,
        /**
         * On an assistant answer, the id of the user question it replies to. Drives the one-active-answer
         * invariant so an edited question's regenerated answer can be linked and de-duplicated.
         */
        public ?int $replyToMessageId = null,
        /**
         * On an assistant answer, where the answer came from (store_knowledge / store_rule / common_rule /
         * fallback). Null on user messages and on legacy answers.
         */
        public ?AnswerSource $answerSource = null,
    ) {}

    public static function user(int $conversationId, string $content): self
    {
        return new self($conversationId, MessageRole::User, $content);
    }
}
