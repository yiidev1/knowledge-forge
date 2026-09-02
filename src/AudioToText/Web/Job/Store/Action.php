<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\AudioUploadValidator;
use App\AudioToText\Application\SeparateUploadValidator;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Application\WorkerHealthService;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Web\AudioToTextRoute;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Http\Method;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function ceil;
use function is_array;
use function is_string;
use function max;
use function min;
use function number_format;

/**
 * One store's audio page (GET and POST /audio-to-text/store/{sourceId}): upload here, and see what
 * has been uploaded here.
 *
 * Like the page it replaces, this action **validates and queues, and nothing else** — no binary is
 * named, no transcription happens in the request. `WebTierCannotRunWhisperTest` walks every
 * `src/*​/Web` directory and fails the build if that changes.
 *
 * ## The store comes from the route, never from the body
 *
 * `{sourceId}` is the only source of the store association. A posted `store_id` would let anyone who
 * can reach one store's page write a conversation onto another store's history, and the route already
 * says which store this is — so the body is never consulted for it.
 *
 * ## Two upload modes
 *
 * `COMMON` is the existing behaviour: one mixed recording, speakers discovered by the pipeline.
 * `SEPARATE` is a Customer file and an Agent file whose roles the administrator has told us, so
 * diarization never runs for them. Both produce one conversation; the difference is how many
 * recordings hang off it.
 *
 * ## An inactive store accepts no new recordings
 *
 * The picker already shows such a store's card disabled, but that is a hint and this is the rule: a
 * POST for a store Order58 reports as inactive is refused here, before anything is stored. The page
 * itself stays readable — its history has to remain reachable, and the global conversions list links
 * straight to it — so what is withheld is the upload, not the record.
 */
final readonly class Action
{
    /** Conversations per page of the store's history. */
    private const PER_PAGE = 20;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AudioStoreLookupInterface $stores,
        private AudioConversationRepositoryInterface $conversations,
        private AudioUploadValidator $validator,
        private SeparateUploadValidator $separateValidator,
        private TranscriptionQueue $queue,
        private WorkerHealthService $workerHealth,
        private AudioToTextSettings $settings,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(#[RouteArgument] int $sourceId, ServerRequestInterface $request): ResponseInterface
    {
        $store = $this->stores->findBySourceId($sourceId);

        if ($store === null) {
            // Back to the picker rather than a 404 page: an id that no longer resolves is almost
            // always a stale bookmark, and the list is where the administrator wanted to be anyway.
            return $this->redirect->toRoute('order58.store-audio');
        }

        $mode = ConversationMode::Common;
        $errors = [];

        if ($request->getMethod() === Method::POST && !$store->active) {
            // Refused before a byte is stored, and never silently: an administrator who got here from
            // a stale tab needs to know why nothing happened.
            $errors['form'] = [
                'Order58 reports this store as inactive, so no new recordings can be uploaded for it. '
                . 'Conversions already made for it are still readable below.',
            ];
        } elseif ($request->getMethod() === Method::POST) {
            $body = $request->getParsedBody();
            $mode = ConversationMode::fromStorage(
                is_array($body) && is_string($body['mode'] ?? null) ? (string) $body['mode'] : null,
            ) ?? ConversationMode::Common;

            [$errors, $files] = $this->collect($request, $mode);

            if ($errors === []) {
                try {
                    $conversationId = $this->queue->enqueueConversation(
                        $mode,
                        $store->sourceId,
                        $files,
                        $this->currentAdmin->get()->id(),
                    );

                    // To the conversion, not back to this page. For a common upload that redirects on
                    // to the job page an administrator already knows — where they can watch it
                    // process — and for a pair it is the one screen that shows both recordings.
                    return $this->redirect->afterPost(
                        AudioToTextRoute::CONVERSION,
                        ['publicId' => $conversationId],
                    );
                } catch (AudioTranscriptionException $e) {
                    // getMessage() is the uploader-facing half. technicalDetail() stays out of the
                    // browser and goes to the log, which the queue and the worker write.
                    $errors['form'] = [$e->getMessage()];
                }
            }
        }

        $total = $this->conversations->countForStore($store->sourceId);
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($this->requestedPage($request), $pageCount);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'store' => $store,
                'mode' => $mode,
                // Drives both the forms and the notice. Read from the store, so it cannot disagree
                // with the card that led here.
                'canUpload' => $store->active,
                'errors' => $errors,
                'conversations' => $this->conversations->forStore(
                    $store->sourceId,
                    self::PER_PAGE,
                    ($page - 1) * self::PER_PAGE,
                ),
                'total' => $total,
                'page' => $page,
                'pageCount' => $pageCount,
                'worker' => $this->workerHealth->status(),
                'maxUploadLabel' => $this->settings->transcription->maxUploadLabel(),
                'maxDurationLabel' => $this->settings->transcription->maxDurationLabel(),
                'extensionList' => $this->settings->transcription->allowedExtensionList(),
                'retentionHours' => $this->settings->transcription->retentionHours(),
                'combinedLimitLabel' => $this->megabytes($this->separateValidator->aggregateLimitBytes()),
                'appTimeZone' => $this->appTimeZone,
            ]);
    }

    /**
     * The uploaded files for this mode, keyed by role, and whatever is wrong with them.
     *
     * Validation happens before a single byte is stored, and for a pair both files are checked even
     * when the first already failed — fixing one problem per submission is a worse experience than
     * being told about both at once.
     *
     * @return array{array<string, list<string>>, array<string, UploadedFileInterface>}
     */
    private function collect(ServerRequestInterface $request, ConversationMode $mode): array
    {
        if ($mode === ConversationMode::Common) {
            $file = $this->file($request, 'audio');
            $messages = $this->validator->validate($file);

            // The validator reports a missing file itself, so a null here always arrives with a
            // message; the second test only tells that to the type checker.
            if ($messages !== [] || $file === null) {
                return [['audio' => $messages], []];
            }

            return [[], [SourceRole::Common->value => $file]];
        }

        $customer = $this->file($request, 'customer_audio');
        $agent = $this->file($request, 'agent_audio');
        $errors = $this->separateValidator->validate($customer, $agent);

        if ($errors !== [] || $customer === null || $agent === null) {
            return [$errors, []];
        }

        return [[], [
            SourceRole::Customer->value => $customer,
            SourceRole::Agent->value => $agent,
        ]];
    }

    private function file(ServerRequestInterface $request, string $field): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()[$field] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    private function requestedPage(ServerRequestInterface $request): int
    {
        $params = $request->getQueryParams();

        return is_string($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1_048_576, 1, '.', '') . ' MB';
    }
}
