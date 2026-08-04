<?php

declare(strict_types=1);

namespace App\Document\Web\ManualText;

use App\Document\Application\Order58\UpdateOrder58DocumentService;
use App\Document\Application\ReplaceBinaryDocumentService;
use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Application\Text\ManualTextService;
use App\Document\Application\Text\TextUpdateOutcome;
use App\Document\Application\Text\UpdateUploadedTextService;
use App\Document\Application\ServeCanonicalDocumentService;
use App\Document\Domain\Exception\UploadException;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function fclose;
use function fopen;
use function fwrite;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

/**
 * POST …/documents/{id}/edit — routes to the correct update service by source type / kind.
 */
final readonly class UpdateAction
{
    private const FILE_FIELD = 'replacement';
    private const CHUNK = 65536;

    public function __construct(
        private ManualTextService $manualText,
        private UpdateUploadedTextService $uploadedText,
        private UpdateOrder58DocumentService $order58,
        private ReplaceBinaryDocumentService $binary,
        private ServeCanonicalDocumentService $serve,
        private DocumentStorageInterface $storage,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $slug,
        #[RouteArgument]
        int $documentId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->finder->bySlug($slug);
        $document = $this->serve->find($documentId, $knowledgeBase->id());
        $form = FormData::fromRequest($request);

        try {
            if ($document->isManualText()) {
                $outcome = $this->manualText->update(
                    $documentId,
                    $knowledgeBase->id(),
                    $form->string('title'),
                    $form->raw('content'),
                );
                $this->flashOutcome($outcome, 'Manual text');
            } elseif ($document->isUploadedText()) {
                $outcome = $this->uploadedText->update(
                    $documentId,
                    $knowledgeBase->id(),
                    $form->string('title'),
                    $form->raw('content'),
                );
                $this->flashOutcome($outcome, 'Text document');
            } elseif ($document->isOrder58()) {
                $outcome = $this->order58->update(
                    $documentId,
                    $knowledgeBase->id(),
                    $form->string('title'),
                    $form->raw('content'),
                );
                $this->flashOutcome($outcome, 'Order58 document');
            } elseif ($document->isBinaryUpload()) {
                $temp = $this->captureReplacement($request);
                $outcome = $this->binary->update(
                    $documentId,
                    $knowledgeBase->id(),
                    $form->string('title'),
                    $temp,
                );
                $this->flashOutcome($outcome, 'Document');
            } else {
                $this->flash->error('This document cannot be edited.');
            }

            return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
        } catch (UploadException $e) {
            $this->flash->error($e->getMessage());

            return $this->redirect->afterPost('kb.documents.edit.show', [
                'slug' => $slug,
                'documentId' => $documentId,
            ]);
        }
    }

    private function flashOutcome(TextUpdateOutcome $outcome, string $label): void
    {
        if ($outcome === TextUpdateOutcome::Reindexed) {
            $this->flash->success($label . ' saved and queued for re-indexing.');
        } else {
            $this->flash->success($label . ' saved. Indexed content unchanged, so it was not re-indexed.');
        }
    }

    private function captureReplacement(ServerRequestInterface $request): ?string
    {
        $file = $request->getUploadedFiles()[self::FILE_FIELD] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        $temporaryPath = $this->storage->createTemporaryFile();
        $in = $file->getStream();
        if ($in->isSeekable()) {
            $in->rewind();
        }
        $out = fopen($temporaryPath, 'wb');
        if ($out === false) {
            return null;
        }
        while (!$in->eof()) {
            fwrite($out, $in->read(self::CHUNK));
        }
        fclose($out);

        return $temporaryPath;
    }
}
