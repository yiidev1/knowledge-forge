<?php

declare(strict_types=1);

namespace App\Document\Web\Upload;

use App\Document\Application\UploadDocumentService;
use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\Exception\UploadException;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function fclose;
use function fopen;
use function fwrite;

use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

/**
 * Handles a document upload (POST /knowledge-bases/{slug}/documents).
 *
 * The action's only job is transport: capture the PSR-7 upload into a temporary file — streamed in
 * fixed-size chunks so a large PDF never sits in memory — and hand that file to the upload service,
 * which owns all validation and persistence. Post/redirect/get with a flash message throughout.
 */
final readonly class Action
{
    private const FIELD = 'document';
    private const CHUNK = 65536;

    public function __construct(
        private UploadDocumentService $uploadService,
        private DocumentStorageInterface $storage,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $file = $this->uploadedFile($request);

        $error = $this->transportError($file);
        if ($error !== null) {
            $this->flash->error($error);

            return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
        }

        /** @var UploadedFileInterface $file */
        $temporaryPath = $this->storage->createTemporaryFile();
        $this->streamToFile($file, $temporaryPath);

        try {
            $this->uploadService->upload(
                $knowledgeBase->id(),
                (string) $file->getClientFilename(),
                $temporaryPath,
            );
            $this->flash->success('Document uploaded and queued for processing.');
        } catch (UploadException $e) {
            // Every upload exception carries a message already safe to show.
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }

    private function uploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()[self::FIELD] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /**
     * @return string|null A user-facing message when the upload never arrived intact, or null when the
     *                     file is present and the bytes can be read.
     */
    private function transportError(?UploadedFileInterface $file): ?string
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return 'Choose a file to upload.';
        }

        return match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large to upload.',
            default => 'The file could not be uploaded. Please try again.',
        };
    }

    private function streamToFile(UploadedFileInterface $file, string $path): void
    {
        $in = $file->getStream();
        if ($in->isSeekable()) {
            $in->rewind();
        }

        $out = fopen($path, 'wb');
        if ($out === false) {
            return;
        }

        while (!$in->eof()) {
            fwrite($out, $in->read(self::CHUNK));
        }

        fclose($out);
    }
}
