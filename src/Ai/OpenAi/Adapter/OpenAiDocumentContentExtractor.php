<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Adapter;

use App\Ai\Contract\Dto\ExtractionResult;
use App\Ai\Contract\DocumentContentExtractorInterface;
use App\Ai\OpenAi\Client\OpenAiClientInterface;
use App\Ai\OpenAi\Dto\OpenAiResponse;
use App\Ai\OpenAi\Dto\OpenAiResponseRequest;
use App\Ai\OpenAi\OpenAiCredentials;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Implements Markdown extraction over the Responses API with a vision-capable model.
 *
 * A standalone image is sent inline as a base64 data URL, so no temporary remote file is created. A
 * scanned PDF is uploaded (purpose `user_data`), referenced by id, and the temporary upload is deleted
 * afterwards in all cases — the derived Markdown, not the original PDF, is what gets indexed.
 */
final readonly class OpenAiDocumentContentExtractor implements DocumentContentExtractorInterface
{
    private const PDF_PURPOSE = 'user_data';

    public function __construct(
        private OpenAiClientInterface $client,
        private OpenAiCredentials $credentials,
        private int $maxOutputTokens,
    ) {}

    public function extractFromImage(string $imageDataUrl, string $prompt): ExtractionResult
    {
        $request = $this->request([
            ['type' => 'input_text', 'text' => $prompt],
            ['type' => 'input_image', 'image_url' => $imageDataUrl, 'detail' => 'high'],
        ]);

        return $this->toResult($this->client->createResponse($request));
    }

    public function extractFromPdf(StreamInterface $pdf, string $filename, string $prompt): ExtractionResult
    {
        $file = $this->client->uploadFile($pdf, $filename, self::PDF_PURPOSE);

        try {
            $request = $this->request([
                ['type' => 'input_text', 'text' => $prompt],
                ['type' => 'input_file', 'file_id' => $file->id],
            ]);

            return $this->toResult($this->client->createResponse($request));
        } finally {
            // Best-effort removal of the temporary upload; a failure here must not mask the real
            // outcome, and any orphan is swept by the GC command later.
            try {
                $this->client->deleteFile($file->id);
            } catch (Throwable) {
                // ignore
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $contentParts
     */
    private function request(array $contentParts): OpenAiResponseRequest
    {
        return new OpenAiResponseRequest(
            model: $this->credentials->visionModel,
            input: [['role' => 'user', 'content' => $contentParts]],
            maxOutputTokens: $this->maxOutputTokens,
            store: false,
        );
    }

    private function toResult(OpenAiResponse $response): ExtractionResult
    {
        return new ExtractionResult(
            markdown: $response->outputText,
            usage: $response->usage,
            providerResponseId: $response->id,
            model: $response->model,
        );
    }
}
