<?php

declare(strict_types=1);

namespace App\Document\Application;

/**
 * Configurable limits governing uploads and, later, processing.
 *
 * Built once from environment values in DI and injected, so business code never reads env directly.
 * The image byte cap is separate from and smaller than the general upload cap, because images are held
 * in memory and base64-encoded for the vision step (Phase 5); the pixel caps bound decode memory.
 */
final readonly class DocumentProcessingParams
{
    public function __construct(
        public int $maxUploadBytes,
        public int $maxImageBytes,
        public int $maxDocumentsPerKnowledgeBase,
        public int $imageMaxWidth,
        public int $imageMaxHeight,
    ) {}
}
