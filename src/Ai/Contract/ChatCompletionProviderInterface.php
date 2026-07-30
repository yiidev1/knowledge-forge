<?php

declare(strict_types=1);

namespace App\Ai\Contract;

use App\Ai\Contract\Dto\GroundedAnswerRequest;
use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Ai\Contract\Exception\AiException;

/**
 * Produces a grounded answer from a knowledge base's vector store.
 *
 * The one synchronous AI port, called from the web tier during chat. The application depends only on
 * this; the OpenAI implementation is one adapter behind it, so the AI provider can be replaced without
 * touching the chat service.
 */
interface ChatCompletionProviderInterface
{
    /**
     * @throws AiException on any provider failure (already normalised and redacted).
     */
    public function ask(GroundedAnswerRequest $request): GroundedAnswerResult;
}
