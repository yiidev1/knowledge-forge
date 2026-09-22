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
use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\OrderId;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionProvider;
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
use function sprintf;
use function trim;

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
        private AudioToTextSettingsRepositoryInterface $settingsRepository,
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

        // Echoed back into the three forms so a rejected submission keeps what was typed. Empty on a
        // GET, which is what an untouched optional field should be.
        $orderId = '';

        // The stored global default, read once per request so the page shows what is configured now
        // rather than what was configured at deploy time. **Never reassigned**: the forms report it as
        // "Global default: …", and an upload must not be able to make that line lie.
        $globalDefault = $this->settingsRepository->defaultProvider();

        // What the selects preselect, which is not always the global default — see preselected().
        $provider = $this->preselected($globalDefault);
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

            [$provider, $providerError] = $this->provider($body, $provider);
            if ($providerError !== null) {
                $errors['transcription_provider'] = [$providerError];
            }

            // Collected before the queue is touched, and a bad value joins the same error list every
            // other refusal uses — so nothing is stored, no job is created and no recording is written
            // to disk for an upload that named an order this application would not accept.
            $orderId = $this->text($body, 'order_id');
            $orderIdError = OrderId::validate($orderId);
            if ($orderIdError !== null) {
                $errors['order_id'] = [$orderIdError];
            }

            if ($errors === []) {
                try {
                    $conversationId = $this->queue->enqueueConversation(
                        $mode,
                        $store->sourceId,
                        $files,
                        $this->currentAdmin->get()->id(),
                        $provider,
                        // Recorded on the conversation and acted on later, by the workers. Nothing in
                        // this request contacts a speech provider: an upload must not be made to wait
                        // on a third party, and generating a long call's audio takes minutes.
                        $this->wantsAiAudio($body) && $this->settings->ttsIsUsable(),
                        // Which card this came from, and which order it belongs to. Both are recorded
                        // on the conversation and read only by the history: the mode above is still
                        // what decides how the recording is processed.
                        $this->recordingType($body, $mode),
                        OrderId::fromInput($orderId),
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
                // What was typed, so a refused submission does not silently discard it.
                'orderId' => $orderId,
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
                // What the selects preselect: the resolved default on a GET, or what was posted on a
                // rejected POST, so a failed submission does not silently reset the choice.
                'provider' => $provider,
                // EVERY provider, always, whether this machine can run it or not. An unusable one is
                // shown disabled and labelled rather than omitted: a field that disappears leaves an
                // administrator with no way to see what the choice even was, and no way to tell a
                // one-provider install from a broken one.
                'providerChoices' => TranscriptionProvider::all(),
                // Which of them can actually run here. LOCAL configuration only — nothing in this
                // request path opens a socket to a provider. See provider() below.
                'providerUsable' => $this->providerUsability(),
                // The stored setting, reported as-is. Shown even when it names a provider that cannot
                // currently run, because silently substituting another one would hide a real problem.
                'globalDefault' => $globalDefault,
                // Whether the opt-in below the provider select can do anything. LOCAL configuration
                // only, like every other readiness question on this page — rendering it opens no socket.
                'ttsConfigured' => $this->settings->ttsIsUsable(),
            ]);
    }

    /**
     * Which provider the two selects start on.
     *
     * Normally the global default. The exception is the case worth getting right: the default names a
     * provider this machine cannot currently run — a key removed, a model deleted since it was chosen.
     *
     * Preselecting it anyway would put the form in a state that cannot be submitted, and would need a
     * disabled option to be `selected`, which browsers still submit. So the first provider that *can*
     * run is preselected instead, and the template says plainly that the configured default is
     * unavailable.
     *
     * **The stored setting is not touched.** An upload page is not the place to silently rewrite a
     * global configuration decision; the operator fixes the provider or changes the default
     * deliberately.
     *
     * With nothing usable at all the global default is kept, the form cannot be submitted, and the
     * template explains why — which is more honest than preselecting an arbitrary broken option.
     */
    private function preselected(TranscriptionProvider $globalDefault): TranscriptionProvider
    {
        if ($this->settings->providerIsUsable($globalDefault)) {
            return $globalDefault;
        }

        foreach (TranscriptionProvider::all() as $provider) {
            if ($this->settings->providerIsUsable($provider)) {
                return $provider;
            }
        }

        return $globalDefault;
    }

    /**
     * Every provider, and whether this machine can run it, keyed by storage value.
     *
     * Computed once per render rather than per option: each answer is a handful of filesystem stats
     * and both upload forms ask the same question.
     *
     * **Local configuration only.** No provider's API is contacted to answer this — see the class
     * docblock and {@see provider()}.
     *
     * @return array<string, bool>
     */
    private function providerUsability(): array
    {
        $usable = [];

        foreach (TranscriptionProvider::all() as $provider) {
            $usable[$provider->value] = $this->settings->providerIsUsable($provider);
        }

        return $usable;
    }

    /**
     * The provider for this upload, and why it was refused if it was.
     *
     * Three outcomes, and they are deliberately distinct:
     *
     *  - **No field posted.** An older form, or a browser that dropped it. The global default stands;
     *    this is not an error.
     *  - **A value that is not a provider.** A tampered or stale form. Refused, because
     *    `fromStorage()` returns null rather than defaulting and silently accepting Whisper for a
     *    request that asked for something else would report success for a choice nobody made.
     *  - **A real provider this server cannot run.** Refused *before* anything is stored or queued,
     *    with the reason. Checking it here rather than letting the worker discover it saves a recording
     *    from being uploaded, converted and then failed for a condition that was knowable at the click.
     *
     * The readiness check is {@see AudioToTextSettings::providerIsUsable()} — **local configuration
     * only.** Nothing in this request path opens a socket to a provider: an upload must not wait on a
     * third party, and a provider outage must not be reported to an administrator as a misconfiguration.
     *
     * @param mixed $body the parsed request body, in whatever shape it arrived
     *
     * @return array{TranscriptionProvider, string|null}
     */
    private function provider(mixed $body, TranscriptionProvider $default): array
    {
        $posted = is_array($body) && is_string($body['transcription_provider'] ?? null)
            ? (string) $body['transcription_provider']
            : null;

        if ($posted === null || $posted === '') {
            return [$default, null];
        }

        $provider = TranscriptionProvider::fromStorage($posted);

        if ($provider === null) {
            return [$default, 'Choose one of the listed transcription providers.'];
        }

        if (!$this->settings->providerIsUsable($provider)) {
            return [
                $default,
                sprintf(
                    '%s is not configured on this server yet, so it cannot be used for this recording. '
                        . 'Choose another provider, or ask an administrator to finish setting it up.',
                    $provider->label(),
                ),
            ];
        }

        return [$provider, null];
    }

    /**
     * Whether the uploader ticked the AI-audio box.
     *
     * An unchecked checkbox posts nothing at all, so absence is the "no" — which is exactly what makes
     * the default of off reliable rather than something the form has to remember to say.
     *
     * @param mixed $body the parsed request body, in whatever shape it arrived
     */
    private function wantsAiAudio(mixed $body): bool
    {
        return is_array($body) && ($body['generate_ai_audio'] ?? null) !== null;
    }

    /**
     * Which of the three upload cards this submission came from, if it said.
     *
     * ## Why the form carries it, and why that is still safe
     *
     * All three cards post to this one action with the same mode and the same field names — nothing
     * about the request path distinguishes them — so the card has to name itself. What makes that
     * trustworthy is not the posted string but {@see RecordingType::fromStorage()}: an **allow-list of
     * exactly three values**, applied here, and the only route a value has into the column. `caller`,
     * `Caller`, `anything`, an array, a missing field — none of them become a recording type, and none
     * of them is persisted.
     *
     * **An unrecognised value is never an error.** It records nothing, and the upload proceeds as the
     * plain COMMON upload it has always been: the history then reads "Common / Mixed", which is what a
     * conversation whose card is unknown honestly is. Refusing the recording instead would let a stale
     * open tab cost somebody their upload over a caption.
     *
     * A `SEPARATE` upload records nothing either — none of these three values describes a Customer +
     * Agent pair, and its mode already does.
     *
     * @param mixed $body the parsed request body, in whatever shape it arrived
     */
    private function recordingType(mixed $body, ConversationMode $mode): ?RecordingType
    {
        if ($mode !== ConversationMode::Common) {
            return null;
        }

        return RecordingType::fromStorage($this->text($body, 'recording_type'));
    }

    /**
     * One posted text field, trimmed, or '' when it was absent or was not a scalar.
     *
     * @param mixed $body the parsed request body, in whatever shape it arrived
     */
    private function text(mixed $body, string $field): string
    {
        return is_array($body) && is_string($body[$field] ?? null) ? trim((string) $body[$field]) : '';
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
