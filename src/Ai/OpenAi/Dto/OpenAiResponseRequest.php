<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

/**
 * A typed request to the Responses API. The `input` items are pre-shaped by the adapter (message parts,
 * input_image, input_file), and this object assembles the final JSON body, omitting anything unset so
 * the wire payload stays minimal.
 */
final readonly class OpenAiResponseRequest
{
    /**
     * @param list<array<string, mixed>> $input     Response input items.
     * @param list<array<string, mixed>> $tools     Tool definitions (e.g. a file_search tool).
     * @param list<string>               $include   Extra fields to include, e.g. file_search_call.results.
     * @param array{type: string}|string|null $toolChoice
     * @param array<string, mixed>|null       $reasoning Reasoning controls, e.g. `['effort' => 'low']`.
     */
    public function __construct(
        public string $model,
        public array $input,
        public ?string $instructions = null,
        public array $tools = [],
        public array|string|null $toolChoice = null,
        public array $include = [],
        public ?int $maxOutputTokens = null,
        public bool $store = false,
        public ?array $reasoning = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'model' => $this->model,
            'input' => $this->input,
            'store' => $this->store,
        ];

        if ($this->instructions !== null) {
            $body['instructions'] = $this->instructions;
        }
        if ($this->tools !== []) {
            $body['tools'] = $this->tools;
        }
        if ($this->toolChoice !== null) {
            $body['tool_choice'] = $this->toolChoice;
        }
        if ($this->include !== []) {
            $body['include'] = $this->include;
        }
        if ($this->maxOutputTokens !== null) {
            $body['max_output_tokens'] = $this->maxOutputTokens;
        }
        // On a reasoning model the reasoning tokens come out of the same allowance as
        // `max_output_tokens`. Left unset, thinking can consume the whole budget and the visible,
        // citation-carrying answer is never written.
        if ($this->reasoning !== null) {
            $body['reasoning'] = $this->reasoning;
        }

        return $body;
    }
}
