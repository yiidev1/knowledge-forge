<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Console;

use App\Ai\Contract\Exception\AiAuthenticationFailed;
use App\Ai\Contract\Exception\AiException;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\OpenAi\Client\OpenAiClientInterface;
use App\Ai\OpenAi\Dto\OpenAiResponseRequest;
use App\Ai\OpenAi\OpenAiCredentials;
use App\Shared\Application\Health\HealthCheck;
use App\Shared\Application\Health\HealthStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

use function implode;

/**
 * Verifies that the configured OpenAI models are reachable and support what the application needs.
 *
 * Beyond "is the key valid", it actually exercises each capability: a minimal chat response, an image
 * input against the vision model, and a forced file_search call against a throwaway vector store (which
 * it creates and deletes). This is where a model id the account cannot use, or one that does not support
 * hosted file search, is caught — at setup, not mid-answer. Makes real API calls, so it needs a live key.
 */
#[AsCommand(
    name: 'kf:openai:ping',
    description: 'Verifies OpenAI model access and required capabilities.',
)]
final class OpenAiPingCommand extends Command
{
    public function __construct(
        private readonly OpenAiClientInterface $client,
        private readonly OpenAiCredentials $credentials,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OpenAI connectivity and capability check');

        if (!$this->credentials->isComplete()) {
            $io->error(
                'OpenAI is not fully configured. Set: ' . implode(', ', $this->credentials->missingVariables()),
            );

            return ExitCode::DATAERR;
        }

        $checks = [
            $this->probeChatModel(),
            $this->probeVisionModel(),
            $this->probeFileSearch(),
        ];

        $rows = [];
        $status = HealthStatus::Ok;
        foreach ($checks as $check) {
            $status = $status->worseOf($check->status);
            $rows[] = [$this->badge($check->status), $check->name, $check->message . ($check->detail !== null ? "\n  " . $check->detail : '')];
        }

        $io->table(['', 'Capability', 'Result'], $rows);

        if ($status === HealthStatus::Failure) {
            $io->error('One or more capability checks failed. Fix the items marked FAIL.');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $io->success('OpenAI is reachable and the required capabilities are available.');

        return ExitCode::OK;
    }

    private function probeChatModel(): HealthCheck
    {
        try {
            $this->client->createResponse(new OpenAiResponseRequest(
                model: $this->credentials->chatModel,
                input: [['role' => 'user', 'content' => 'Reply with the single word: OK.']],
                maxOutputTokens: 16,
                store: false,
            ));

            return HealthCheck::ok('chat.model', sprintf('Chat model "%s" responded.', $this->credentials->chatModel));
        } catch (AiAuthenticationFailed $e) {
            return HealthCheck::failure('chat.model', 'Authentication failed. Check OPENAI_API_KEY.', $e->getMessage());
        } catch (AiException $e) {
            return HealthCheck::failure(
                'chat.model',
                sprintf('Chat model "%s" is not usable.', $this->credentials->chatModel),
                $e->getMessage(),
            );
        }
    }

    private function probeVisionModel(): HealthCheck
    {
        // Build the probe payload from a validated fixture first. If our own image is bad, say so —
        // never blame the model for input we sent.
        try {
            $imageDataUrl = (new VisionProbeImage())->dataUrl();
        } catch (Throwable $e) {
            return HealthCheck::failure(
                'vision.model',
                'The vision probe image fixture is invalid — this is a local fixture problem, not the model.',
                $e->getMessage(),
            );
        }

        try {
            $this->client->createResponse(new OpenAiResponseRequest(
                model: $this->credentials->visionModel,
                input: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'Reply with the single word: OK.'],
                        ['type' => 'input_image', 'image_url' => $imageDataUrl, 'detail' => 'low'],
                    ],
                ]],
                maxOutputTokens: 16,
                store: false,
            ));

            return HealthCheck::ok(
                'vision.model',
                sprintf('Vision model "%s" accepted image input.', $this->credentials->visionModel),
            );
        } catch (AiException $e) {
            return HealthCheck::failure(
                'vision.model',
                sprintf('Vision model "%s" did not accept image input.', $this->credentials->visionModel),
                $e->getMessage(),
            );
        }
    }

    private function probeFileSearch(): HealthCheck
    {
        try {
            $store = $this->client->createVectorStore('kf-ping-probe', ['kf_op' => 'ping']);
        } catch (AiException $e) {
            return HealthCheck::failure('chat.file_search', 'Could not create a probe vector store.', $e->getMessage());
        }

        try {
            $this->client->createResponse(new OpenAiResponseRequest(
                model: $this->credentials->chatModel,
                input: [['role' => 'user', 'content' => 'Reply with the single word: OK.']],
                tools: [['type' => 'file_search', 'vector_store_ids' => [$store->id], 'max_num_results' => 1]],
                toolChoice: ['type' => 'file_search'],
                include: ['file_search_call.results'],
                maxOutputTokens: 16,
                store: false,
            ));

            return HealthCheck::ok('chat.file_search', 'Forced file_search is supported by the chat model.');
        } catch (AiProcessingFailed $e) {
            // The model exists but will not accept a forced hosted-tool choice. The app falls back to
            // auto and the grounding check still governs, so this is a warning, not a failure.
            return HealthCheck::warning(
                'chat.file_search',
                'The chat model rejected a forced file_search; the app will fall back to "auto".',
                $e->getMessage(),
            );
        } catch (AiException $e) {
            return HealthCheck::failure('chat.file_search', 'The file_search capability check failed.', $e->getMessage());
        } finally {
            try {
                $this->client->deleteVectorStore($store->id);
            } catch (Throwable) {
                // best-effort cleanup of the probe store
            }
        }
    }

    private function badge(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Ok => '<fg=green>PASS</>',
            HealthStatus::Warning => '<fg=yellow>WARN</>',
            HealthStatus::Failure => '<fg=red>FAIL</>',
        };
    }
}
