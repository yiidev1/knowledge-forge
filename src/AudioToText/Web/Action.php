<?php

declare(strict_types=1);

namespace App\AudioToText\Web;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\AudioUploadValidator;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Application\WorkerHealthService;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Http\Method;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The upload page (GET and POST /audio-to-text).
 *
 * This action **validates and queues, and nothing else**. It does not convert, transcribe or diarize;
 * it does not know the path to any binary. A regression test walks every `src/*​/Web` directory and
 * fails the build if the transcriber, the diarizer, `ProcessRunner` or `proc_open` are so much as
 * named there.
 *
 * The reason is measured rather than theoretical: transcription takes ninety-four seconds of a CPU
 * core and 834 MB on this hardware. Done inside PHP-FPM that is a worker process held for a minute and
 * a half, a request the browser abandons long before it finishes, and no queue in front of any of it.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AudioUploadValidator $validator,
        private TranscriptionQueue $queue,
        private TranscriptionJobRepositoryInterface $jobs,
        private WorkerHealthService $workerHealth,
        private AudioToTextSettings $settings,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $errors = [];

        if ($request->getMethod() === Method::POST) {
            $file = $this->uploadedFile($request);
            $errors = $this->validator->validate($file);

            if ($errors === [] && $file !== null) {
                try {
                    $publicId = $this->queue->enqueue($file, $this->currentAdmin->get()->id());

                    return $this->redirect->afterPost(AudioToTextRoute::JOB, ['publicId' => $publicId]);
                } catch (AudioTranscriptionException $e) {
                    // getMessage() is the uploader-facing half of the exception. technicalDetail() is
                    // not touched here — it belongs in the log, which the queue and worker write.
                    $errors = [$e->getMessage()];
                }
            }
        }

        return $this->render($errors);
    }

    /**
     * @param list<string> $errors
     */
    private function render(array $errors): ResponseInterface
    {
        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'errors' => $errors,
                'summary' => $this->jobs->summary(),
                'worker' => $this->workerHealth->status(),
                'maxUploadLabel' => $this->settings->transcription->maxUploadLabel(),
                'maxDurationLabel' => $this->settings->transcription->maxDurationLabel(),
                'extensionList' => $this->settings->transcription->allowedExtensionList(),
                'retentionHours' => $this->settings->transcription->retentionHours(),
            ]);
    }

    private function uploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['audio'] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }
}
