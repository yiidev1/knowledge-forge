<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * Token counts for one AI request, normalised across whatever the provider reports.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
