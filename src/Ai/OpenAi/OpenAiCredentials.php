<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

use App\Shared\Domain\Exception\ConfigurationException;
use App\Shared\Domain\ValueObject\SecretValue;
use SensitiveParameter;

use function rtrim;
use function str_starts_with;

/**
 * OpenAI credentials and the two model identifiers, validated for presence but not for capability.
 *
 * Model names are deliberately not defaulted. An identifier the account cannot reach fails at the
 * worst possible moment — mid-ingestion or mid-answer — so the application refuses to use AI features
 * until both are set, and `./yii kf:openai:ping` confirms access and capability before first use.
 */
final readonly class OpenAiCredentials
{
    public SecretValue $apiKey;
    public string $baseUrl;

    public function __construct(
        #[SensitiveParameter]
        string $apiKey,
        string $baseUrl,
        public string $chatModel,
        public string $visionModel,
    ) {
        $this->apiKey = new SecretValue($apiKey);
        $this->baseUrl = rtrim($baseUrl, '/');

        if ($this->baseUrl !== '' && !str_starts_with($this->baseUrl, 'https://') && !str_starts_with($this->baseUrl, 'http://')) {
            throw ConfigurationException::invalid('OPENAI_BASE_URL', 'an absolute http(s) URL');
        }
    }

    /**
     * True when every value needed to talk to OpenAI is present.
     *
     * Callers that merely *report* configuration (the health command, the admin UI) use this instead of
     * triggering the exceptions below, so a half-configured install can still be inspected.
     */
    public function isComplete(): bool
    {
        return !$this->apiKey->isEmpty()
            && $this->baseUrl !== ''
            && $this->chatModel !== ''
            && $this->visionModel !== '';
    }

    /**
     * @return list<string> Names of the variables that still need a value.
     */
    public function missingVariables(): array
    {
        $missing = [];

        if ($this->apiKey->isEmpty()) {
            $missing[] = 'OPENAI_API_KEY';
        }

        if ($this->baseUrl === '') {
            $missing[] = 'OPENAI_BASE_URL';
        }

        if ($this->chatModel === '') {
            $missing[] = 'OPENAI_CHAT_MODEL';
        }

        if ($this->visionModel === '') {
            $missing[] = 'OPENAI_VISION_MODEL';
        }

        return $missing;
    }

    /**
     * @throws ConfigurationException when a value required to make a request is absent.
     */
    public function assertComplete(): void
    {
        if ($this->apiKey->isEmpty()) {
            throw ConfigurationException::missing('OPENAI_API_KEY', 'authenticate against the OpenAI API');
        }

        if ($this->chatModel === '') {
            throw ConfigurationException::missing('OPENAI_CHAT_MODEL', 'answer questions; verify it with "./yii kf:openai:ping"');
        }

        if ($this->visionModel === '') {
            throw ConfigurationException::missing('OPENAI_VISION_MODEL', 'extract text from images and scanned PDFs; verify it with "./yii kf:openai:ping"');
        }
    }
}
