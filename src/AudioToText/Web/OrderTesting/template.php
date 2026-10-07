<?php

declare(strict_types=1);

use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\OrderId;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\StoreOrderGroup;
use App\Shared\Order58\DemoLinkStatus;
use App\Shared\Order58\DemoOrderUrl;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Domain\WorkerStatusView;
use App\AudioToText\Web\AudioToTextIcons;
use App\AudioToText\Web\AudioToTextViews;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\OrderTesting\OrderTestingRoute;
use App\AudioToText\Web\OrderTesting\OrderTestingAsset;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Audio\RecordingTypeLabels;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var AudioStore $store
 * @var ConversationMode $mode
 * @var string $orderId what was typed into the optional Order ID field, '' on a fresh form
 * @var RecordingType $selectedType which recording the upload form is set to
 * @var bool $uploadOpen whether the upload dialog should render already open
 * @var array<string, list<string>> $errors
 * @var list<StoreOrderGroup> $groups one row per order, newest activity first
 * @var array<string, \App\Shared\Order58\DemoOrderUrl> $demoLinks keyed by the order id each row shows
 * @var array<string, int> $demoOrderCounts demo orders imported per order id, keyed the same way
 * @var int $total
 * @var int $page
 * @var int $pageCount
 * @var int $perPage orders per page; fixed by the Action
 * @var WorkerStatusView $worker
 * @var string $maxUploadLabel
 * @var string $maxDurationLabel
 * @var string $extensionList
 * @var int|null $retentionHours
 * @var string $combinedLimitLabel
 * @var bool $canUpload
 * @var AppTimeZone $appTimeZone
 * @var TranscriptionProvider $provider currently selected, and what all recording cards preselect
 * @var list<TranscriptionProvider> $providerChoices every provider, usable or not
 * @var array<string, bool> $providerUsable storage value => can this server run it (local check only)
 * @var TranscriptionProvider $globalDefault the stored setting, reported as-is and never rewritten here
 * @var bool $ttsConfigured whether clean AI audio can be generated on this server at all
 */

$assetManager->register(OrderTestingAsset::class);

$this->setTitle($store->name . ' — order testing');
// Its own trail, back to its own landing. The audio page's breadcrumb returns to the Order58 picker,
// whose links go to `/audio-to-text/...`; following it from here would move the reader onto the surface
// this one exists to keep them off.
$this->setParameter('breadcrumbs', [
    ['label' => 'Order Testing', 'route' => OrderTestingRoute::PAGE],
    ['label' => $store->name],
]);

$csrfField = (string) $csrf->hiddenInput();

// One per page, not one per row: every Demo URL form on this table posts to the same store-scoped
// address and names its own order in a hidden field.
$demoUrlAction = $urlGenerator->generate(OrderTestingRoute::DEMO_URL, ['sourceId' => $store->sourceId]);
$storeUrl = $urlGenerator->generate(OrderTestingRoute::STORE, ['sourceId' => $store->sourceId]);
// One endpoint for the whole page. The attribute that uses it is rendered only while something is
// still being downloaded, so an idle page never asks.
$arrivingUrl = $urlGenerator->generate(OrderTestingRoute::STORE_ARRIVING, ['sourceId' => $store->sourceId]);
$pageUrl = static fn(int $p): string => $storeUrl . ($p > 1 ? '?page=' . $p : '');

/**
 * @param list<string> $messages
 */
$fieldErrors = static function (array $messages): string {
    $html = '';
    foreach ($messages as $message) {
        $html .= '<div class="field__error">' . Html::encode($message) . '</div>';
    }

    return $html;
};

/**
 * The optional Order ID field.
 *
 * Still a closure rather than inline markup: the dialog renders it once today, and keeping the field's
 * shape in one place is what stopped the three cards it replaced from drifting apart.
 *
 * **Optional, and it says so.** The rule is one line in {@see OrderId} and is enforced on the server;
 * this only reports it. A rejected value is rendered back into the field rather than discarded — it is
 * usually a typo in something the operator had to read off another screen, and making them find it
 * again would be the worst part of the mistake.
 */
$orderIdField = static function (string $id) use ($orderId, $errors, $fieldErrors): string {
    return '<div class="field">'
        . '<label class="field__label" for="' . Html::encode($id) . '">Order ID</label>'
        . '<input class="field__control' . (isset($errors['order_id']) ? ' field__control--error' : '')
        . '" id="' . Html::encode($id) . '" type="text" name="order_id" inputmode="numeric"'
        . ' autocomplete="off" value="' . Html::encode($orderId) . '">'
        . $fieldErrors($errors['order_id'] ?? [])
        . '<div class="field__hint">Optional. Example: 16513791</div>'
        . '</div>';
};

/**
 * The provider select.
 *
 * Unchanged from when three cards each rendered it: the options, the disabled-and-labelled treatment
 * for a provider this machine cannot run, and the note about the global default are all as they were.
 *
 * ## Always rendered, never hidden
 *
 * Every provider appears every time, including ones this machine cannot currently run. Hiding the
 * field when only one provider works looked tidy and was wrong twice over: an administrator could not
 * see which engine their upload would use, and a broken install was indistinguishable from a
 * single-provider one. An unusable provider is shown `disabled` and labelled instead, which says the
 * true thing — the choice exists, this machine cannot make it yet.
 *
 * Availability comes from LOCAL configuration that the action already computed. Rendering this page
 * contacts no provider.
 *
 * `disabled` on an option is a courtesy, not the control: the server refuses an unusable provider
 * whatever is posted.
 */
$providerField = static function (string $id) use (
    $provider,
    $providerChoices,
    $providerUsable,
    $globalDefault,
    $errors,
    $fieldErrors
): string {
    $options = '';
    foreach ($providerChoices as $choice) {
        $usable = $providerUsable[$choice->value] ?? false;

        $options .= '<option value="' . Html::encode($choice->value) . '"'
            . ($usable ? '' : ' disabled')
            . ($choice === $provider ? ' selected' : '') . '>'
            . Html::encode($choice->label() . ($usable ? '' : ' — Not configured'))
            . '</option>';
    }

    // One line per unusable provider, in plain words. Deliberately says nothing about WHICH setting is
    // missing: that is the operator's business and it belongs in the log, not on a page anyone with an
    // upload to make can read.
    $unavailable = '';
    foreach ($providerChoices as $choice) {
        if (($providerUsable[$choice->value] ?? false) === false) {
            $unavailable .= '<div class="field__hint">'
                . Html::encode($choice->shortLabel() . ' is not currently configured on this server.')
                . '</div>';
        }
    }

    // The configured default names something that cannot run. Said out loud rather than papered over:
    // the preselected provider below is NOT the global default, and an administrator who does not know
    // that would reasonably assume their upload used the engine the settings page advertises.
    $defaultNote = ($providerUsable[$globalDefault->value] ?? false) === false
        ? '<div class="field__hint">'
            . Html::encode(
                'The global default is ' . $globalDefault->label() . ', which is not available on this '
                . 'server, so another provider is selected for this upload. The global setting has not '
                . 'been changed.',
            )
            . '</div>'
        : '<div class="field__hint">'
            . Html::encode('Global default: ' . $globalDefault->label() . '.')
            . ' This choice applies only to this upload.'
            . '</div>';

    return '<div class="field">'
        . '<label class="field__label" for="' . Html::encode($id) . '">Transcription provider</label>'
        . '<select class="field__control' . (isset($errors['transcription_provider']) ? ' field__control--error' : '')
        . '" id="' . Html::encode($id) . '" name="transcription_provider">' . $options . '</select>'
        . $fieldErrors($errors['transcription_provider'] ?? [])
        . $defaultNote
        . $unavailable
        . '<div class="field__hint">Speech recognition only — speakers are always worked out on this server.</div>'
        . '</div>';
};

/**
 * The AI-audio opt-in.
 *
 * **Unchecked, always.** It is not sticky and does not remember a previous upload: this spends money
 * with a third party, and a checkbox that quietly stayed on would spend it on recordings nobody decided
 * to spend it on. The cost is stated on the control rather than a click later.
 *
 * Nothing about this makes the upload wait. The preference is recorded on the conversation and acted on
 * afterwards by a worker — no speech provider is contacted in this request.
 */
$aiAudioField = static function (string $id) use ($ttsConfigured): string {
    $hint = $ttsConfigured
        ? 'Costs money. Off by default. A clean synthetic reading of the transcript, for agents who '
            . 'cannot follow the original recording.'
        : 'Not configured on this server yet, so nothing would be generated.';

    return '<div class="field">'
        . '<label class="a2t-checkbox" for="' . Html::encode($id) . '">'
        . '<input type="checkbox" id="' . Html::encode($id) . '" name="generate_ai_audio" value="1"'
        . ($ttsConfigured ? '' : ' disabled') . '>'
        . '<span>Generate clean AI audio after transcription</span>'
        . '</label>'
        . '<div class="field__hint">' . Html::encode($hint) . '</div>'
        . '</div>';
};

// Keep errors readable for a submission from an older, already-open paired-upload form.
$formErrors = array_merge($errors['form'] ?? [], $errors['customer_audio'] ?? [], $errors['agent_audio'] ?? []);

/**
 * A duration as a person reads it.
 *
 * `3:26` rather than `206.3s`: this sits inside a play control, where the number is a length of
 * listening rather than a measurement.
 */
$clock = static function (?float $seconds): string {
    if ($seconds === null || $seconds <= 0) {
        return '--:--';
    }

    $whole = (int) round($seconds);

    return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
};

// Every address a control needs is generated here and printed into the control, never assembled in
// the browser from an id. A deployment prefix, the router's own escaping and the shape of each route
// are all things this side knows and the script deliberately does not — the same rule the upload
// flow already follows with `data-a2t-done`.
$originalUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_ORIGINAL_FILE,
    ['publicId' => $jobPublicId],
);
$generatedUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_AI_AUDIO_FILE,
    ['publicId' => $jobPublicId],
);
$fragmentUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_REVIEW_FRAGMENT,
    ['publicId' => $jobPublicId],
);
// Where a recording that has never been transcribed is asked about. A POST, and it names exactly one
// recording: a call's three channels are three separate recordings and each is asked for on its own.
$transcribeUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_TRANSCRIBE,
    ['publicId' => $jobPublicId],
);
// The polling endpoint, for a recording that is still being transcribed. The same one the Update
// dialog follows — there is one status endpoint and every surface that shows progress reads it.
$statusUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_STATUS,
    ['publicId' => $jobPublicId],
);
$fullReviewUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_REVIEW,
    ['publicId' => $jobPublicId],
);
$groupUrl = static fn(string $route, GroupKey $key): string => $urlGenerator->generate(
    $route,
    ['sourceId' => $store->sourceId, 'groupKey' => $key->value],
);

/**
 * One recording, as a table cell.
 *
 * Play, and a way in. Everything else about the recording lives behind Details, because a cell one
 * third of a column wide is not where an administrator reads a transcript.
 *
 * The play control is a `<button>` rather than an `<audio>` element: sixty native players on one page
 * is sixty pieces of browser chrome and sixty preloads. One shared controller in
 * `audio-store.js` plays them, which is also what makes "only one at a time" possible.
 */
/**
 * One numbered row of the shared progress card, with generic hooks the manual cards are driven by.
 *
 * The upload card below passes its own long-standing attribute names instead, so its script keeps
 * working exactly as it did — the structure is shared, the wiring is each surface's own.
 */
$progressStep = static fn(string $number, string $key, string $label, string $detail): array => [
    'number' => $number,
    'label' => $label,
    'detail' => $detail,
    'value' => 'Waiting',
    'stepAttrs' => ['data-a2t-processing-step' => $key],
    'valueAttrs' => ['data-a2t-processing-value' => true],
    'barAttrs' => ['data-a2t-processing-bar' => true],
    'detailAttrs' => ['data-a2t-processing-detail' => true],
];

/** The five stages a transcription reports, in the order the worker reaches them. */
$transcriptionSteps = static fn(): array => [
    $progressStep('01', 'PREPARING', 'Preparing', 'Getting the recording ready for transcription'),
    $progressStep('02', 'TRANSCRIBING', 'Transcribing', 'Converting speech into text'),
    $progressStep('03', 'DIARIZING', 'Separating speakers', 'Telling the two voices apart'),
    $progressStep('04', 'MAPPING_SPEAKERS', 'Identifying speakers', 'Working out which voice is the agent'),
    $progressStep('05', 'SAVING', 'Saving', 'Writing the finished transcript'),
];

$slotCellInner = static function (
    StoreRecordingSlot $slot,
    bool $isCurrent = false,
    /**
     * Whether this line draws its own play control.
     *
     * False in the conversation cell, where the three channels of one call share a single play button:
     * they are three recordings of the same conversation and their durations are the same number, so
     * three copies of it was the clutter the column was consolidated to remove. Every other control the
     * slot carries is unaffected — this suppresses one button, not a state.
     */
    bool $withPlay = true,
) use (
    $clock,
    $originalUrl,
    $fragmentUrl,
    $fullReviewUrl,
    $statusUrl
): string {
    $html = '<div class="a2t-slot">';

    if ($slot->hasOriginalAudio && $withPlay) {
        $html .= '<button class="a2t-play" type="button"'
            . ' data-a2t-play="' . Html::encode($originalUrl($slot->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play ' . $slot->label() . ' recording') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span>'
            . '<span class="a2t-play__time">' . Html::encode($clock($slot->durationSeconds)) . '</span>'
            . '</button>';
    }

    // Still being transcribed. It has no transcript to review, so `isReviewable()` is false and the
    // Details button below is not drawn — which used to leave the word "Processing" as the only thing
    // anywhere about it. This opens the same Details dialog straight into its progress card, polling
    // the status endpoint that already exists.
    if ($slot->isReadyForTranscription()) {
        $html .= '<span class="a2t-slot__note a2t-slot__note--ready">Ready for transcription</span>';
    }

    if ($slot->isAudioOnly()) {
        // Not "ready for" anything: this is the settled state of a mixed recording, not a step before
        // one. The call's words live in its Customer and Agent recordings.
        $html .= '<span class="a2t-slot__note">Audio only</span>';
    }

    if ($slot->isProcessing()) {
        // The same control and the same words as a current recording's — one way of saying "this is
        // happening, and here is how to watch it", wherever the recording sits.
        $html .= '<button class="a2t-slot__link a2t-slot__link--watch" type="button"'
            . ' data-a2t-progress="' . Html::encode($statusUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">'
            . Html::encode($slot->status->label()) . '…</button>';
    }

    if ($slot->isReviewable()) {
        // `data-a2t-current-channel` marks THE recording this column is about, as opposed to one it
        // superseded — the same closure draws both, and the older ones sit in the hidden fold below.
        // The order dialog builds its tabs from the marked ones, so a side uploaded twice offers one
        // tab rather than two named the same thing. Empty value: it is a marker, and the name it would
        // carry is already on `data-a2t-details-label`.
        $html .= '<button class="a2t-slot__link" type="button"'
            . ($isCurrent ? ' data-a2t-current-channel="' . $slot->channelRank() . '"' : '')
            . ' data-a2t-details="' . Html::encode($fragmentUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">Details</button>';
    }

    return $html . '</div>';
};

/**
 * A channel that has something to read, stated rather than offered.
 *
 * Two parts, and the invisible one is the point. The dialog's tab strip is built in the browser by
 * scanning the row for `data-a2t-current-channel` and reading each channel's endpoint, full-editor url
 * and name off it — those four attributes used to ride on the Details button, and with the button gone
 * they ride on an empty hidden span instead. Data for the script, nothing for the reader, and no change
 * to the script that reads it.
 *
 * The visible part is not a control: no button, no link styling, nothing to press. Pressing it would
 * only reach the dialog the link above it already opens.
 */
$channelIndicator = static function (StoreRecordingSlot $slot) use ($fragmentUrl, $fullReviewUrl): string {
    return '<span hidden'
        . ' data-a2t-current-channel="' . $slot->channelRank() . '"'
        . ' data-a2t-details="' . Html::encode($fragmentUrl($slot->jobPublicId)) . '"'
        . ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
        . ' data-a2t-details-label="' . Html::encode($slot->label()) . '"></span>'
        // `aria-hidden` on the tick: a reader using a screen reader is told the channel's name, which is
        // the information. Read aloud, the glyph would be pronounced as a word.
        . '<span class="a2t-channel-have">' . Html::encode($slot->label())
        . '<span class="a2t-channel-have__tick" aria-hidden="true">&#10003;</span></span>';
};

$slotCell = static function (
    ?StoreRecordingSlot $slot,
    ?RecordingType $emptyType = null,
    ?StoreOrderGroup $group = null,
    bool $withPlay = true,
    /**
     * Whether a recording that can be opened says so instead of offering to open it.
     *
     * True in the conversation cell. There is one way into a conversation — the link above these
     * channels — and a Details button beside each of them was a second route to the same dialog, three
     * times over, which rebuilt the three columns this cell replaced. So the channel states that it has
     * something and stays a statement; the dialog's tabs are how one channel is reached.
     *
     * It changes **only** the two states that used to draw that button. A channel waiting to be
     * transcribed keeps Transcribe audio, one being transcribed keeps its progress link, a replaced
     * recording keeps the fold holding its older versions, and an empty one keeps "+ Add audio" — each
     * the only way to reach what it does.
     */
    bool $asIndicator = false,
) use (
    $clock,
    $originalUrl,
    $fragmentUrl,
    $fullReviewUrl,
    $slotCellInner,
    $channelIndicator,
    $appTimeZone,
    $groupUrl,
    $statusUrl,
    $transcribeUrl,
    $globalDefault
): string {
    if ($slot === null) {
        // Asked for and not here yet. Said plainly, in place of the Add-audio affordance: offering to
        // upload a recording that is already being fetched would invite somebody to do the work twice,
        // and a dash would read as nothing having been asked for.
        //
        // No play button, no Details, and above all no Transcribe — there is no audio to do any of it
        // to. That is structural rather than a rule: all three are drawn from a slot, and there is none.
        $channelKey = $emptyType === null ? null : strtolower($emptyType->value);
        $incoming = $channelKey === null ? null : $group?->arrivingFor($channelKey);

        if ($incoming !== null && $channelKey !== null) {
            return '<span class="a2t-slot__note a2t-slot__note--arriving"'
                . ' data-a2t-arriving-channel="' . Html::encode($channelKey) . '">'
                . Html::encode($incoming->label()) . '…</span>';
        }

        // Nothing here yet — so offer to put something here, rather than printing a dash and leaving
        // the administrator to find the Manage Audio dialog and work out which slot they wanted.
        //
        // It opens THE SAME dialog that button opens, carrying the type this column stands for.
        // `RecordingsAction` already emits an entry for every one of the three types whether or not a
        // recording exists — an empty slot is already offered for upload there — so this adds an
        // affordance and no new endpoint, no second upload path and no new validation.
        //
        // A ghost button: sixty of these on a page of twenty orders must not read as sixty calls to
        // action, so it stays quiet until the row is hovered or the button is focused.
        //
        // Callable only where the group can accept one at all. A legacy Customer + Agent pair has no
        // mixed/caller/callee shape to add to, and an upload that named no order has nothing to group a
        // second recording under — the same two refusals ReplaceAction enforces server-side.
        if ($emptyType === null || $group === null || $group->orderId === null || $group->isLegacySeparate()) {
            return '<span class="util-muted">&mdash;</span>';
        }

        return '<button class="a2t-slot__add" type="button"'
            . ' data-a2t-manage="' . Html::encode($groupUrl(OrderTestingRoute::STORE_GROUP_RECORDINGS, $group->key)) . '"'
            . ' data-a2t-order="' . Html::encode($group->orderId) . '"'
            . ' data-a2t-manage-focus="' . Html::encode($emptyType->value) . '"'
            . ' aria-label="' . Html::encode('Add ' . $emptyType->label() . ' audio for order ' . $group->orderId) . '">'
            . '<span aria-hidden="true">+</span> Add audio</button>';
    }

    $html = '<div class="a2t-slot">';

    if ($slot->hasOriginalAudio && $withPlay) {
        $html .= '<button class="a2t-play" type="button"'
            . ' data-a2t-play="' . Html::encode($originalUrl($slot->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play ' . $slot->label() . ' recording') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span>'
            . '<span class="a2t-play__time">' . Html::encode($clock($slot->durationSeconds)) . '</span>'
            . '</button>';
    } elseif (!$slot->hasOriginalAudio) {
        // Retention took the file, or it was never kept. The transcript is still there, so the row
        // stays useful — it just cannot be listened to.
        $html .= '<span class="a2t-slot__gone" title="This recording is no longer stored on the server">'
            . Html::encode($clock($slot->durationSeconds)) . '</span>';
    }

    if ($slot->isReviewable()) {
        // THE recording this column is about, and WHICH channel it is — 0 mixed, 1 the customer's
        // side, 2 the agent's, from the slot rather than from the cell it landed in.
        //
        // The superseded recordings below it are drawn by `$slotCellInner`, which leaves the marker off,
        // so the order dialog builds one tab per channel rather than one per upload. It sorts on the
        // value, which is what stops a legacy Customer + Agent pair — whose halves share ONE cell and
        // are rendered in the order their jobs were inserted — from being ordered by that accident.
        $html .= $asIndicator ? $channelIndicator($slot) : '<button class="a2t-slot__link" type="button"'
            . ' data-a2t-current-channel="' . $slot->channelRank() . '"'
            . ' data-a2t-details="' . Html::encode($fragmentUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">Details</button>';
    } elseif ($slot->isReadyForTranscription()) {
        // Audio and nothing else. No Details, because there is no transcript to show — opening an empty
        // correction editor would be pretending there is. One action, and it is the only one that makes
        // sense: ask for the text.
        $html .= '<span class="a2t-slot__note a2t-slot__note--ready">Ready for transcription</span>'
            . '<button class="a2t-slot__link a2t-slot__link--go" type="button"'
            . ' data-a2t-transcribe="' . Html::encode($transcribeUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-status="' . Html::encode($statusUrl($slot->jobPublicId)) . '"'
            // What the confirmation names, carried on the button that opens it so the dialog
            // describes THE recording that was clicked rather than whichever one was looked at last.
            . ' data-a2t-transcribe-order="' . Html::encode($group?->orderId ?? '') . '"'
            . ' data-a2t-transcribe-duration="' . Html::encode($clock($slot->durationSeconds)) . '"'
            . ' data-a2t-transcribe-provider="' . Html::encode($globalDefault->shortLabel()) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">Transcribe audio</button>';
    } elseif ($slot->isAudioOnly()) {
        // A mixed recording: the playable original of the call, and never converted to text. Details
        // still opens, because there is something to show — the conversation assembled from this call's
        // Customer and Agent recordings, or, when it has none, the audio and a sentence saying so.
        // Offering "Transcribe audio" here would offer something the endpoint refuses.
        // The "Audio only" note goes with the button here: it describes the recording, and the dialog
        // this channel belongs to already says so in a sentence of its own.
        $html .= $asIndicator ? $channelIndicator($slot) : '<span class="a2t-slot__note">Audio only</span>'
            . '<button class="a2t-slot__link" type="button"'
            . ' data-a2t-current-channel="' . $slot->channelRank() . '"'
            . ' data-a2t-details="' . Html::encode($fragmentUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">Details</button>';
    } elseif ($slot->status->value === 'FAILED') {
        $html .= '<span class="a2t-slot__note">Failed</span>';
    } elseif ($slot->isProcessing()) {
        // A way back into the progress view, not just a word about it.
        //
        // This was a plain span reading "Converting…", which is a dead end in two ways: it names a
        // stage rather than the state, and it carries nothing — so a reader who closed the dialog, or
        // simply refreshed, had no route back to the thing still running. The url is server-rendered,
        // so reopening survives a reload and depends on no memory of the click that started it.
        //
        // Opening it POSTs nothing: {@see showProgressOn} reads the status endpoint and resumes the
        // same poll. The worker is untouched either way — closing the dialog never stopped it.
        //
        // The status's own word rather than a fixed "Transcribing": a recording that has been asked
        // for but not started is "Transcription requested", and saying otherwise would claim work that
        // is not happening yet. Both are active and both open the same view.
        $html .= '<button class="a2t-slot__link a2t-slot__link--watch" type="button"'
            . ' data-a2t-progress="' . Html::encode($statusUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">'
            . Html::encode($slot->status->label()) . '…</button>';
    } elseif ($slot->status->value !== 'COMPLETED') {
        $html .= '<span class="a2t-slot__note">Converting…</span>';
    }

    if ($slot->olderCount() > 0) {
        // Nothing is hidden, only folded: every earlier recording keeps its own play, Details and
        // transcript, rendered here and moved into the history dialog when it opens. Rendered rather
        // than fetched because it is three short rows the page already had in hand — an endpoint for
        // it would be a request to learn something this page already knows.
        $html .= '<button class="a2t-slot__more" type="button"'
            . ' data-a2t-history="' . Html::encode($slot->jobPublicId) . '">+'
            . $slot->olderCount() . ' more</button>'
            . '<div class="a2t-history-source" data-a2t-history-for="'
            . Html::encode($slot->jobPublicId) . '" hidden>';

        foreach ($slot->older as $older) {
            $html .= '<div class="a2t-history-row">'
                . '<span class="a2t-history-row__when">'
                . Html::encode($appTimeZone->format($older->uploadedAt, 'M j, Y g:i A'))
                . '</span>'
                . '<span class="a2t-history-row__meta">'
                . Html::encode($older->label() . ' · ' . $older->provider->label()
                    . ' · ' . $older->status->label())
                . '</span>'
                . $slotCellInner($older)
                . '</div>';
        }

        $html .= '</div>';
    }

    return $html . '</div>';
};

/**
 * One channel of a call, as the table shows it.
 *
 * ## Why a recording that can be opened shows no link
 *
 * There is one way into a conversation and it is "View conversation", which opens the dialog on the
 * right tab. A Details link beside each channel was a second way to the same place, and three of them
 * rebuilt the three columns this cell replaced — the clutter, inside one box.
 *
 * So a channel that has something to read is a **statement, not a control**: its name and a tick. It is
 * not a button, carries no link styling and does nothing when clicked, because offering an action that
 * duplicates the one above it is what made the row hard to read.
 *
 * ## The hidden carrier, and why it is not an oversight
 *
 * The dialog's tab strip is built in the browser by scanning this row for `data-a2t-current-channel`
 * and reading each channel's endpoint, full-editor url and name off it. Those four attributes used to
 * ride on the Details button. With the button gone they need somewhere to live, so they ride on an
 * empty hidden span instead — data for the script, nothing for the reader, and no change to the script
 * that reads it.
 *
 * ## What is still a control
 *
 * Everything that is not a duplicate. A channel waiting to be transcribed keeps Transcribe audio, one
 * being transcribed keeps its progress link, and a channel that was never uploaded keeps "+ Add audio" —
 * each the only way to reach what it does, and none of them a second route to the dialog.
 */
$channelLine = static function (
    ?StoreRecordingSlot $slot,
    ?RecordingType $type,
    ?StoreOrderGroup $group,
) use ($slotCell): string {
    // "Readable" is exactly the two states that used to draw a Details button, and the only ones that
    // become an indicator. The name is drawn here only for the others: an indicator carries its own, and
    // printing it twice was the first thing that made this cell look like three columns again.
    $readable = $slot !== null && ($slot->isReviewable() || $slot->isAudioOnly());

    return ($readable ? '' : '<span class="a2t-conversation__name">'
            . Html::encode($type === null ? ($slot?->label() ?? '') : $type->label())
            . '</span>')
        // Still the one renderer. A replaced recording keeps the fold holding its older versions, an
        // empty channel keeps "+ Add audio", and a running one keeps its progress link — none of which
        // this cell could have reproduced without owning a second copy of the same state machine.
        . $slotCell($slot, $type, $group, false, $readable);
};

/**
 * One call's recordings as a single cell.
 *
 * ## Why three columns became one
 *
 * The table used to carry a Mix / Common, a Customer and an Agent column, each with its own play
 * control, duration and Details button. Three identical durations and three near-identical buttons read
 * as three different things to do, when they are three recordings of **one conversation** and the thing
 * to do with them is the same: open it. The unified dialog that does so already existed behind the order
 * number — this gives it a name and puts it where the recordings were.
 *
 * ## What is kept, and why the per-channel controls are still here
 *
 * Every control {@see $slotCell} draws is still drawn, with the same data attributes: the dialog's tab
 * strip is built in the browser by scanning this row for `data-a2t-current-channel`, and the Transcribe,
 * watch-progress, Add audio and version-history controls are each the only way to reach what they do. So
 * the channels are compacted, never dropped — what is suppressed is one duplicated play button per
 * channel, because all three report the same number.
 *
 * Clicking a channel still opens that channel. The dialog lands on its tab, which is the behaviour the
 * three Details buttons had, so nothing a reader could do before has moved.
 */
$conversationCell = static function (StoreOrderGroup $group) use (
    $clock,
    $originalUrl,
    $channelLine
): string {
    // The first line: what there is to listen to, and the one way in. Wrapped together so they stay on
    // one line and the channels below read as a caption to them.
    $html = '<div class="a2t-conversation"><div class="a2t-conversation__lead">';

    // One play control for the call, from whichever recording of it can still be played. They are the
    // same call and the same length; which file backs the button is not a distinction worth drawing.
    $playable = null;

    foreach ($group->primaries() as $slot) {
        if ($slot->hasOriginalAudio) {
            $playable = $slot;

            break;
        }
    }

    if ($playable !== null) {
        $html .= '<button class="a2t-play" type="button"'
            . ' data-a2t-play="' . Html::encode($originalUrl($playable->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play the recording of this call') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span>'
            . '<span class="a2t-play__time">' . Html::encode($clock($playable->durationSeconds)) . '</span>'
            . '</button>';
    }

    // The named way in. It carries `data-a2t-order-open` because that is the attribute the dialog's
    // existing opener reads — the order number keeps working and this is the same door, not a second one.
    if ($group->orderId !== null && $group->hasReviewableRecording()) {
        $html .= '<button class="a2t-slot__link a2t-slot__link--open" type="button"'
            . ' data-a2t-order-open="' . Html::encode($group->orderId) . '">View conversation</button>';
    }

    $html .= '</div><div class="a2t-conversation__channels">';

    if ($group->isLegacySeparate()) {
        // A pair uploaded before recording types existed: its halves are Customer and Agent, named by
        // the role the administrator supplied rather than by a channel this application never knew.
        // Kept inside `a2t-legacy` so the caption and its two halves stay one thing on the page.
        $html .= '<span class="a2t-legacy">'
            . '<span class="a2t-legacy__tag">Customer + Agent</span>';

        foreach ($group->legacySeparate as $half) {
            // Named by the half's own `source_role`, because a legacy pair has no recording type to be
            // named by — the same distinction the caption above draws.
            $html .= '<span class="a2t-conversation__channel"'
                . ' data-a2t-channel="' . Html::encode($half->sourceRole->value) . '">'
                . $channelLine($half, null, null)
                . '</span>';
        }

        $html .= '</span>';
    } else {
        foreach (
            [
                [$group->mixed, RecordingType::Mixed],
                [$group->caller, RecordingType::Caller],
                [$group->callee, RecordingType::Callee],
            ] as [$slot, $type]
        ) {
            // The channel this line is about, as the enum spells it. A reader sees the label; this is
            // what lets anything else — a test, a style, a script — address one channel now that the
            // three of them share a cell and can no longer be told apart by which column they sit in.
            $html .= '<span class="a2t-conversation__channel"'
                . ' data-a2t-channel="' . Html::encode($type->value) . '">'
                . $channelLine($slot, $type, $group)
                . '</span>';
        }
    }

    return $html . '</div></div>';
};

/**
 * The generated-audio side of one recording.
 *
 * A play control only when there are bytes to play. Every other state is a word, because a button that
 * cannot do anything is worse than a sentence saying why.
 */
$ttsCell = static function (StoreRecordingSlot $slot) use ($generatedUrl): string {
    // Two grid cells rather than a row wrapper: the whole column is one grid, so every recording's
    // status starts at the same x whatever its label is, and neither half ever wraps mid-phrase.
    $html = '<span class="a2t-tts__label">' . Html::encode($slot->label()) . '</span>';

    if ($slot->hasGeneratedAudio()) {
        return $html
            . '<button class="a2t-play a2t-play--sm" type="button"'
            . ' data-a2t-play="' . Html::encode($generatedUrl($slot->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play generated ' . $slot->label() . ' audio') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span></button>';
    }

    return $html . '<span class="a2t-tts__state">'
        . Html::encode($slot->aiAudioState()->label()) . '</span>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($store->name) ?></h1>
        <p class="page-header__subtitle">
            Order testing for this store. Call recordings grouped by the order they belong to.
        </p>
    </div>
    <div class="page-header__actions">
        <?php
        // A real link, so the dialog is reachable with scripts off: the server answers `?upload=1` by
        // rendering the dialog already open. The script upgrades the same element to `showModal()`.
?>
        <?php if ($canUpload): ?>
            <a class="btn btn--primary" href="<?= Html::encode($storeUrl . '?upload=1') ?>"
               data-a2t-open="a2t-upload-dialog">+ Add Audio</a>
        <?php endif; ?>
        <?php // Order Testing's own list, not the Order58 picker, whose links go to the audio page.?>
        <a class="btn" href="<?= Html::encode($urlGenerator->generate(OrderTestingRoute::PAGE)) ?>">All stores</a>
    </div>
</div>

<p class="util-muted util-mono">
    Store #<?= $store->sourceId ?><?php if ($store->company !== null): ?> &middot; <?= Html::encode($store->company) ?><?php endif; ?>
    <?php if (!$store->active): ?> &middot; <span class="badge badge--error">Source inactive</span><?php endif; ?>
</p>

<?php
// Only when something is wrong. An administrator about to queue a recording needs to know when nothing
// is going to pick it up; the full counters strip belongs on the conversions list.
?>
<?php if (!$worker->isHealthy()): ?>
    <div class="alert alert--warning" role="status">
        <p>
            <?= Html::encode($worker->label()) ?><?php if ($worker->detail() !== null): ?> &mdash; <?= Html::encode($worker->detail()) ?><?php endif; ?>
        </p>
        <p>Uploads are still accepted and will be transcribed once the worker is running again.</p>
    </div>
<?php endif; ?>

<?php if (!$canUpload): ?>
    <div class="alert alert--warning" role="status">
        <p>
            Order58 reports this store as inactive, so no new recordings can be uploaded for it.
            Everything already converted for it is listed below.
        </p>
    </div>
<?php endif; ?>

<?php
// Where an in-page action reports itself. The layout renders server-side flashes above this; a
// generation asked for from the dialog never reloads, so it says so here instead — same `.alert`
// shell, so the two read as one thing.
?>
<div class="a2t-notice" data-a2t-notice role="status" aria-live="polite" hidden></div>

<div class="card a2t-wide">
    <h2 class="card__title">This store's conversions</h2>

    <?php if ($groups === []): ?>
        <?php // A short sentence and the one action that changes it, rather than an empty table.?>
        <div class="a2t-empty-state">
            <p>No audio recordings yet.</p>
            <?php if ($canUpload): ?>
                <a class="btn btn--primary" href="<?= Html::encode($storeUrl . '?upload=1') ?>"
                   data-a2t-open="a2t-upload-dialog">+ Add Audio</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php
        // Polling hooks. The attribute is rendered only while something is still being downloaded, so a
        // page with nothing arriving — almost every page view — makes no requests at all. `landed` is
        // what had already arrived when this was drawn: a rise in it means the server has a player to
        // render that the browser cannot build, and the page reloads once rather than assembling one.
        $arrivingCalls = [];
        $landed = 0;

        foreach ($groups as $group) {
            if ($group->arriving !== null && $group->arriving->isActive()) {
                $arrivingCalls[] = $group;
                $landed += $group->arriving->available();
            }
        }
        ?>
        <div class="a2t-table-scroll"
            <?php if ($arrivingCalls !== []): ?>
                data-a2t-arriving-poll="<?= Html::encode($arrivingUrl) ?>"
                data-a2t-arriving-landed="<?= Html::encode((string) $landed) ?>"
            <?php endif; ?>>
            <table class="table a2t-table a2t-orders a2t-conversions">
                <?php
                        // Sized to content, with the one recording column taking the slack: a filename no
                        // longer appears here, so nothing in this table has an unbounded length.
?>
                <colgroup>
                    <col class="a2t-col-order">
                    <col class="a2t-col-conversation">
                    <col class="a2t-col-status">
                    <col class="a2t-col-demo">
                    <col class="ot-col-demo-orders">
                    <col class="a2t-col-tts">
                    <col class="a2t-col-row-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Order No#</th>
                        <?php // One column, because an order is one conversation. The channels it was?>
                        <?php // recorded on are named inside the cell, from the enum rather than typed?>
                        <?php // out here, and the dialog it opens gives each of them a tab.?>
                        <th>Recording / Conversation</th>
                        <th>Status</th>
                        <th>Demo URL</th>
                        <?php // Immediately after Demo URL, because it is the result of pressing it:?>
                        <?php // the orders a trainee produced from this call, each compared on its own.?>
                        <th>Demo Orders</th>
                        <th>Text to Audio</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <?php $status = $group->aggregateStatus(); ?>
                    <tr<?= $group->arriving !== null
                ? ' data-a2t-arriving-call="' . Html::encode($group->arriving->callSessionId) . '"'
                : '' ?>>
                        <td>
                            <?php if ($group->orderId !== null && $group->hasReviewableRecording()): ?>
                                <?php // One way in to every recording of this call. The channels it?>
                                <?php // offers are the Details buttons already in this row, so the?>
                                <?php // page carries nothing extra for it and nothing is fetched?>
                                <?php // until it is opened.?>
                                <button class="a2t-order-id a2t-order-id--open" type="button"
                                        data-a2t-order-open="<?= Html::encode($group->orderId) ?>">#<?= Html::encode($group->orderId) ?></button>
                            <?php elseif ($group->orderId !== null): ?>
                                <?php // Nothing in this row has finished, so there is nothing for a?>
                                <?php // details view to show. Manage Audio is where it is watched.?>
                                <span class="a2t-order-id">#<?= Html::encode($group->orderId) ?></span>
                            <?php else: ?>
                                <?php
                // An upload that named no order is still its own row — never merged
                // with every other order-less upload. It says so rather than showing
                // a blank cell that would read as missing data.
                                ?>
                                <span class="util-muted">No order</span>
                            <?php endif; ?>
                            <?php
                            // Two different facts that were being shown as one. The call time is the
                            // provider's own string, printed exactly as sent and with NO zone label —
                            // it carries none, and appending one would be inventing a claim. The import
                            // time is this application's own instant, so it gets the business timezone
                            // every other timestamp on this page is formatted in.
                    ?>
                            <?php if ($group->callTimeRaw !== null): ?>
                                <span class="a2t-order-when">
                                    <span class="a2t-order-when__key">Call time</span>
                                    <?= Html::encode($group->callTimeRaw) ?>
                                </span>
                            <?php endif; ?>
                            <span class="a2t-order-when">
                                <span class="a2t-order-when__key"><?= $group->callTimeRaw === null ? 'Added' : 'Downloaded' ?></span>
                                <?= Html::encode($appTimeZone->format($group->latestActivityAt, 'M j, Y g:i A')) ?>
                            </span>
                        </td>
                        <td>
                            <?= $conversationCell($group) ?>
                        </td>
                        <td>
                            <?php
                            // A row whose audio is still arriving says so, instead of reporting a
                            // transcription status for recordings that are not here to transcribe. The
                            // two numbers are kept apart for the reason they are everywhere in this
                            // feature: the count is channels the provider has answered for, and the
                            // line under it is what actually arrived.
                    ?>
                            <?php if ($group->arriving !== null && $group->arriving->isActive()): ?>
                                <?php
                                // The transcription panel's shape and its step marks, reusing its
                                // PRIMITIVES — `.a2t-processing__stages`, `__stage`, `__mark` — rather
                                // than its shell classes. That card's markup lives in one partial and
                                // must stay there (ManualProcessingPanelTest enforces it); this is a
                                // different thing wearing the same clothes, so it owns its shell and
                                // shares the parts that make the two read as one product.
                                //
                                // Drawn only while a channel is still pending or downloading. Once the
                                // acquisition settles the call is no longer reported as arriving at
                                // all, so this disappears and the row falls back to its ordinary
                                // recordings — which is what the reader actually wants to see.
                                ?>
                                <?php
                // A badge in the cell, the detail in a modal.
                //
                // This panel is seven lines of progress and it used to render in full in every arriving
                // row, which made the table scroll for pages. An inline disclosure fixed the height but
                // not the width: a summary's parts have no column to wrap into, so on a real store they
                // spilled sideways across the Demo URL cell. A modal takes the detail out of the table's
                // geometry entirely, which is the only way a seven-line panel and a fixed column width
                // can both be satisfied.
                //
                // `:target`, not JavaScript: the policy here is `script-src 'self'` with no inline
                // handlers. The modal also stays *inside* this `<tr>`, because the poller in
                // audio-store.js finds its targets with `row.querySelector(...)` — a hidden modal keeps
                // every one of them in the DOM, so the figures stay live while it is shut and are
                // current the moment it opens.
                $arrivingId = 'a2t-arriving-' . $group->arriving->callSessionId;
                                ?>
                                <a class="a2t-badge a2t-badge--processing a2t-arriving__open"
                                   href="#<?= Html::encode($arrivingId) ?>">Downloading<span
                                   class="a2t-arriving__caret" aria-hidden="true">&rsaquo;</span></a>

                                <div class="a2t-modal" id="<?= Html::encode($arrivingId) ?>"
                                     role="dialog" aria-modal="true" aria-label="Download progress">
                                    <a class="a2t-modal__backdrop" href="#" aria-label="Close"></a>
                                    <div class="a2t-modal__panel">
                                        <div class="a2t-modal__head">
                                            <div>
                                                <h3 class="a2t-modal__title">Downloading recordings</h3>
                                                <p class="a2t-modal__sub">
                                                    <?= $group->orderId === null
                                                        ? 'No order'
                                                        : 'Order #' . Html::encode($group->orderId) ?>
                                                    &middot;
                                                    <span data-a2t-arriving-outcome><?=
                                                        Html::encode($group->arriving->outcome()->label())
                                ?></span>
                                                </p>
                                            </div>
                                            <a class="btn btn--sm a2t-modal__close" href="#">Close</a>
                                        </div>

                                        <div class="a2t-modal__body a2t-arriving">
                                            <div class="a2t-arriving__overall">
                                                <div class="a2t-arriving__label">
                                                    <span class="a2t-arriving__title">Overall progress</span>
                                                    <?php
                        // The one place this figure lives. The poller takes the first match in the row,
                        // so a second copy in the cell would leave one of the two stale.
                                ?>
                                                    <span data-a2t-arriving-progress><?=
                                    Html::encode($group->arriving->progressText())
                                ?></span>
                                                </div>
                                        <progress class="a2t-arriving__bar" max="100"
                                            value="<?= $group->arriving->percentChecked() ?>"
                                            aria-label="<?= Html::encode($group->arriving->progressText()) ?>"
                                            data-a2t-arriving-bar></progress>
                                    </div>

                                    <ol class="a2t-processing__stages">
                                        <?php foreach ($group->arriving->channels as $channel => $state): ?>
                                            <li class="a2t-processing__stage"
                                                data-state="<?= Html::encode($state->step()) ?>"
                                                data-a2t-arriving-step="<?= Html::encode($channel) ?>">
                                                <span class="a2t-processing__mark" aria-hidden="true"></span>
                                                <?= Html::encode(
                                                    RecordingTypeLabels::forStorageValue(strtoupper($channel)),
                                                ) ?>
                                                <span data-a2t-arriving-channel="<?= Html::encode($channel) ?>">
                                                    <?= Html::encode($state->label()) ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>

                                    <p class="a2t-arriving__detail" role="status" data-a2t-arriving-current>
                                        <?= Html::encode((string) $group->arriving->currentStep()) ?>
                                    </p>
                                    <?php // What actually arrived — never inferred from the bar.?>
                                    <p class="a2t-arriving__detail" data-a2t-arriving-availability>
                                        <?= Html::encode($group->arriving->availabilityText()) ?>
                                    </p>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="a2t-badge a2t-badge--<?= Html::encode($status->badgeModifier()) ?>">
                                    <?= Html::encode($status->label()) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="a2t-cell-tight">
                            <?php
            // Resolved for the whole page in one query before rendering; this cell only prints the
            // answer. Every row was asked about, so a missing key would be a bug rather than a blank.
            // The Action asks about every row, so a missing key would be a bug — but an explicit
            // "not found" is a safer answer than a null nobody checks, and it keeps this cell to one
            // type throughout.
            $orderKey = $group->orderId ?? '';
                    $demo = array_key_exists($orderKey, $demoLinks)
                        ? $demoLinks[$orderKey]
                        : DemoOrderUrl::unavailable(DemoLinkStatus::OrderNotFound);
                    ?>
                            <?php if ($demo->isReady()): ?>
                                <?php
                // A POST, not a link — and this is the one place on either surface where the two
                // templates genuinely differ in behaviour rather than in wording.
                //
                // Order58's demo document carries no field this application controls, so the ONLY
                // moment at which "who is testing this order" can be captured is this click. A plain
                // anchor cannot capture it. The form writes the attempt first, then the action
                // redirects to the Order58 address — which it builds from the mirrored host and phone
                // through DemoOrderUrl's allow-list, never from anything the browser sent.
                //
                // `target="_blank"` keeps the behaviour an operator already had: the browser opens the
                // new tab, posts into it, and follows the redirect there. `rel` still matters on it —
                // the destination carries a customer's phone number in its path, and without
                // `noreferrer` the browser would hand this store page's URL to it as the Referer.
                                ?>
                                <form class="ot-demo-form" method="post" target="_blank"
                                      rel="noopener noreferrer"
                                      action="<?= Html::encode($demoUrlAction) ?>">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="source_order_id"
                                           value="<?= Html::encode($orderKey) ?>">
                                    <?php
                // `data-ot-demo-url` is what `order-testing.js` binds to. It is a real submit button
                // and the form is a real form: with the script absent the browser posts it, the
                // attempt is still recorded, and the server answers in the new tab with a page
                // carrying the link. The script only spares the operator that second click.
                                ?>
                                    <button class="ot-linkbutton" type="submit"
                                            data-ot-demo-url>Demo URL</button>
                                </form>
                                <?php // Filled by the script on a refusal — most often "somebody else is testing this".?>
                                <span class="ot-demo-message" role="alert" data-ot-demo-message hidden></span>
                            <?php else: ?>
                                <?php // Plain text, not a dead link: nothing to click is clearer than something that cannot work.?>
                                <span class="util-muted"><?= Html::encode($demo->status->message()) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="a2t-cell-tight">
                            <?php
                // Counted for the whole page in one query before rendering; this cell prints the
                // answer and links on to Order Testing's own page for that order. A dash is the honest
                // answer for "nothing has been created from this call yet" — a "View (0)" would be a
                // link to an empty page.
                $demoCount = $demoOrderCounts[$orderKey] ?? 0;
                    ?>
                            <?php if ($orderKey !== '' && $demoCount > 0): ?>
                                <a href="<?= Html::encode($urlGenerator->generate(
                                    OrderTestingRoute::DEMO_ORDERS,
                                    ['sourceId' => $store->sourceId, 'sourceOrderId' => $orderKey],
                                )) ?>">View (<?= Html::encode((string) $demoCount) ?>)</a>
                            <?php else: ?>
                                <span class="util-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $primaries = $group->primaries(); ?>
                            <?php if ($primaries === []): ?>
                                <span class="util-muted">&mdash;</span>
                            <?php else: ?>
                                <div class="a2t-tts-list">
                                    <?php foreach ($primaries as $slot): ?>
                                        <?= $ttsCell($slot) ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="a2t-cell-actions">
                            <?php if ($group->hasAnyTranscript()): ?>
                                <button class="a2t-slot__link" type="button"
                                        data-a2t-transcripts="<?= Html::encode(
                                            $groupUrl(OrderTestingRoute::STORE_GROUP_TRANSCRIPTS, $group->key),
                                        ) ?>"
                                        data-a2t-order="<?= Html::encode($group->orderId ?? '') ?>">
                                    Original transcript
                                </button>
                            <?php endif; ?>
                            <?php
                            // Withheld for a row with nothing transcribed. AI audio is a reading of a
                            // transcript, so offering it where there is none is offering to read
                            // nothing aloud — the same rule the action above it has always followed.
                    ?>
                            <?php if ($group->hasAnyTranscript()): ?>
                                <button class="a2t-slot__link" type="button"
                                        data-a2t-tts="<?= Html::encode(
                                            $groupUrl(OrderTestingRoute::STORE_GROUP_TTS_OPTIONS, $group->key),
                                        ) ?>"
                                        data-a2t-order="<?= Html::encode($group->orderId ?? '') ?>">
                                    Generate Text to Audio
                                </button>
                            <?php endif; ?>
                            <?php
                            // Offered for every row, including one whose recordings cannot be replaced:
                            // the dialog is also where an administrator reads what an order holds and
                            // why a replacement is refused, and a missing button answers neither.
                    ?>
                            <button class="a2t-slot__link" type="button"
                                    data-a2t-manage="<?= Html::encode(
                                        $groupUrl(OrderTestingRoute::STORE_GROUP_RECORDINGS, $group->key),
                                    ) ?>"
                                    data-a2t-order="<?= Html::encode($group->orderId ?? '') ?>">
                                Manage Audio
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pageCount > 1): ?>
            <?= $this->render(dirname(__DIR__, 4) . '/Web/Shared/_partial/pager', [
                'page' => $page,
                'pageCount' => $pageCount,
                'pageUrl' => $pageUrl,
            ]) ?>
        <?php endif; ?>

        <?php
        // The window this page is showing, not just the total — the question a pager raises. The size
        // is fixed in the Action and handed here, so this sentence and the offsets cannot disagree.
        $first = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$last = min($page * $perPage, $total);
?>
        <p class="util-muted">
            Showing <?= $first ?>&ndash;<?= $last ?> of <?= $total ?>
            order<?= $total === 1 ? '' : 's' ?> for this store, newest first.
            Every recording of one order shares its row.
        </p>
    <?php endif; ?>
</div>

<p class="util-muted">
    <?php if ($retentionHours === null): ?>
        Conversions and their recordings are kept on this server indefinitely.
    <?php else: ?>
        Conversions and their recordings are kept for <?= $retentionHours ?> hours, then removed.
    <?php endif; ?>
</p>

<?php // ---- The dialogs -------------------------------------------------------------------------?>
<?php if ($canUpload): ?>
    <?php
    // ONE form, where there were three. The recording type it posts is a radio rather than three
    // hidden inputs; everything else — the field names, the CSRF token, the progress hooks the upload
    // script binds to — is exactly what the cards posted, because the pipeline behind it is unchanged.
    //
    // `.a2t-uploads` is load-bearing: the upload script scrapes `.a2t-uploads .field__error` to report
    // a server-side refusal, so a field error outside that ancestor would silently become a generic
    // "could not confirm this upload".
    ?>
    <dialog class="source-modal a2t-upload-dialog" id="a2t-upload-dialog" data-a2t-dialog
            aria-labelledby="a2t-upload-title"<?= $uploadOpen ? ' open' : '' ?>>
        <div class="source-modal__head">
            <h2 class="source-modal__title" id="a2t-upload-title">Add audio</h2>
            <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="source-modal__body a2t-uploads">
            <?php if ($formErrors !== []): ?>
                <div class="alert alert--error" role="alert">
                    <?php foreach ($formErrors as $error): ?>
                        <p><?= Html::encode($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="a2t-upload-form" data-a2t-upload data-a2t-stay id="a2t-upload-form"
                  method="post" action="<?= Html::encode($storeUrl) ?>" enctype="multipart/form-data">
                <?= $csrfField ?>
                <input type="hidden" name="mode" value="<?= Html::encode(ConversationMode::Common->value) ?>">

                <div class="field">
                    <label class="field__label" for="a2t-audio">Audio file</label>
                    <?php
                    // A <label> wrapping the input, not a scripted drop zone: the CSP forbids inline
                    // script and the upload has to keep working with scripts off, which is exactly the
                    // path where a scripted picker would be dead.
    ?>
                    <label class="a2t-upload-picker" for="a2t-audio">
                        <svg class="a2t-upload-picker__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 16V3m-4 4 4-4 4 4M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>
                        </svg>
                        <span class="a2t-upload-picker__title" aria-hidden="true">Choose an audio file</span>
                        <span class="a2t-upload-picker__hint" aria-hidden="true">Browse files to get started</span>
                        <input class="a2t-upload-input<?= isset($errors['audio']) ? ' field__control--error' : '' ?>"
                               id="a2t-audio" type="file" name="audio"
                               accept=".wav,.mp3,.m4a,.ogg,.webm,audio/*" aria-describedby="a2t-audio-limits">
                    </label>
                    <div class="a2t-upload-file" data-a2t-file hidden></div>
                    <?= $fieldErrors($errors['audio'] ?? []) ?>
                    <div class="field__hint" id="a2t-audio-limits">
                        <?= Html::encode($extensionList) ?><br>
                        Up to <?= Html::encode($maxUploadLabel) ?> &middot; <?= Html::encode($maxDurationLabel) ?> maximum
                    </div>
                </div>

                <?= $orderIdField('a2t-order-id') ?>

                <div class="field">
                    <span class="field__label">Recording type</span>
                    <?php
    // The one thing three cards said that one form has to say some other way. The
    // server allow-lists whatever arrives, so this is a convenience, not the control.
    ?>
                    <div class="a2t-type-choice" data-a2t-type-choice>
                        <?php foreach (RecordingType::cases() as $type): ?>
                            <label class="a2t-checkbox" for="a2t-type-<?= Html::encode(strtolower($type->value)) ?>">
                                <input type="radio" name="recording_type"
                                       id="a2t-type-<?= Html::encode(strtolower($type->value)) ?>"
                                       value="<?= Html::encode($type->value) ?>"
                                    <?= $type === $selectedType ? 'checked' : '' ?>>
                                <span><?= Html::encode($type->label()) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="field__hint">
                        Which side of the call this file holds. A Caller or Callee file holds one
                        person, so its speaker is taken as given — the Customer or the Agent — and
                        nothing is guessed from the audio.
                    </div>
                    <?php
    // Shown for Mix / Common, hidden for the two sides. The browser toggles it; the server decides
    // the same thing either way, so the page is describing a rule rather than setting one.
    ?>
                    <div class="field__hint a2t-type-note" data-a2t-mixed-note hidden>
                        A Mix / Common file holds both people on one track, so it is stored as the
                        playable original of the call and is not converted to text. The call's words
                        come from its Customer and Agent recordings — upload those under the same
                        order to build the conversation.
                    </div>
                </div>

                <?php
    // Both of these describe what happens to a transcript, so neither applies to a Mix / Common file:
    // nothing transcribes one. Wrapped rather than removed because the choice is made in the browser
    // and this form has no page reload to rebuild itself on — and with scripting off the wrapper
    // simply stays visible, which costs nothing because the server refuses the transcription anyway.
    ?>
                <div data-a2t-transcription-options>
                    <?= $providerField('a2t-provider') ?>
                    <?= $aiAudioField('a2t-ai-audio') ?>
                </div>

                <?php
                // The same card the manual dialogs render — one file, one structure. This one names the
                // attribute hooks its own script has always used, so nothing about the upload flow
                // changes; what is shared is the markup, not the wiring.
    ?>
                <?= $this->render(AudioToTextViews::processingCard(), [
                    'title' => 'Recording progress',
                    'cardAttrs' => ['data-a2t-feedback' => true],
                    'badgeAttrs' => ['data-a2t-state-label' => true],
                    'badgeText' => 'Ready',
                    'steps' => [
                        [
                            'number' => '01',
                            'label' => 'Upload',
                            'detail' => 'Awaiting audio file',
                            'value' => '0%',
                            'stepAttrs' => ['data-a2t-step' => 'upload'],
                            'valueAttrs' => ['data-a2t-upload-percent' => true],
                            'barAttrs' => ['data-a2t-upload-progress' => true],
                            'detailAttrs' => ['data-a2t-upload-status' => true],
                        ],
                        [
                            'number' => '02',
                            'label' => 'Conversion',
                            'detail' => 'Starts after upload',
                            'value' => 'Pending',
                            'stepAttrs' => ['data-a2t-step' => 'conversion'],
                            'valueAttrs' => ['data-a2t-conversion-percent' => true],
                            'barAttrs' => ['data-a2t-conversion-progress' => true],
                            'detailAttrs' => ['data-a2t-conversion-status' => true],
                        ],
                    ],
                    'errorAttrs' => ['data-a2t-upload-error' => true],
                    // Two steps that really are separate — bytes arriving, then a worker converting
                    // — so a bar each is the honest shape here.
                    'layout' => 'steps',
                    // Inline in a form, not in a dialog: no estimate row, no close-is-safe note and no
                    // buttons of its own — the form's own submit is right beneath it.
                    'meta' => false,
                    'note' => false,
                    'closable' => false,
                    'extra' => '<a class="a2t-upload-result" data-a2t-upload-result hidden>'
                        . 'View conversion <span aria-hidden="true">&#8599;</span></a>',
                ]) ?>

                <?php
    // The label is a promise about what the press does, so it follows the type: a Mix / Common file is
    // uploaded and nothing else. `data-a2t-transcribe-label` is what the script swaps.
    ?>
                <button class="btn btn--primary a2t-upload-submit" type="submit"
                        data-a2t-upload-submit
                        data-a2t-transcribe-label="Upload &amp; Transcribe"
                        data-a2t-store-label="Upload">Upload &amp; Transcribe</button>
            </form>
        </div>
    </dialog>
<?php endif; ?>

<?php
    // The three read/edit dialogs are rendered empty and filled on demand: a store page must not carry
    // every transcript of every row it lists. Each one follows the shell the chat source dialog
    // established — a `<dialog>` with data hooks, no ids printed, closed by its own button or a backdrop
    // click, and filled with textContent rather than markup.
?>
<dialog class="source-modal a2t-review-dialog" id="a2t-review-dialog" data-a2t-dialog
        aria-labelledby="a2t-review-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-review-title" data-a2t-review-title>Recording details</h2>
            <p class="source-modal__meta" data-a2t-review-meta></p>
        </div>
        <div class="a2t-dialog__actions">
            <?php
            // What can be DONE to this recording, filled by the script from the same payload the panel
            // below is drawn from. In the header rather than in a row of its own under the players:
            // those players are three ways of hearing one recording and read as a unit, and a strip of
            // buttons beneath them cost a line of the dialog's height to say something the header had
            // room for. Left empty here — a recording that cannot be replaced or generated from gets no
            // buttons at all, and an empty container is nothing on screen.
?>
            <div class="a2t-dialog__acts" data-a2t-review-actions></div>
            <?php
            // Drag-to-move and select-to-merge measure the review page's own scroll container and
            // cannot work in a dialog, so they stay where they work and this is the way to them.
?>
            <a class="btn btn--sm" data-a2t-full-editor href="#">Open full editor</a>
        </div>

        <?php
        // A child of the header, NOT of the actions beside it. When the header runs out of width the
        // actions drop to a line of their own, and a close button inside them would go with them — so
        // the one control every dialog is expected to have in its top-right corner would be somewhere
        // else, on exactly the screens where it is hardest to find.
?>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
            title="Close" aria-label="Close">&times;</button>
    </div>
    <?php
    // A recording that is still being transcribed has no transcript to show, and used to be a dead end:
    // the row offered no Details at all and the only word anywhere was "Processing". The same card the
    // Update dialog uses now answers that, polling the same endpoint, so closing one window and opening
    // another shows the same state rather than two different accounts of it.
?>
    <?= $this->render(AudioToTextViews::processingCard(), [
        'title' => 'Transcription Progress',
        'cardAttrs' => ['data-a2t-processing' => 'details'],
        'badgeAttrs' => ['data-a2t-processing-badge' => true],
        'badgeText' => 'Starting',
        'steps' => $transcriptionSteps(),
        'errorAttrs' => ['data-a2t-processing-error' => true],
        'layout' => 'overall',
        'meta' => true,
        'note' => true,
        'closable' => false,
        'extra' => '',
    ]) ?>
    <?php
// One tab per recording of the call, when this dialog was opened from an order id. Built by the
// script from the Details buttons the row already carries, so nothing is rendered here and nothing
// is fetched until a tab is chosen.
//
// Hidden throughout the single-recording path, and hidden for an order with only one recording:
// the Original transcript dialog above draws its tab bar on the same rule, and a tab strip holding
// one tab is a control that cannot do anything.
?>
    <div class="a2t-tabs" role="tablist" data-a2t-review-tabs hidden></div>
    <p class="source-modal__status" data-a2t-review-status hidden></p>
    <?php
    // The token this dialog's corrections are sent with. A rendered input, exactly as every form on
    // this page uses, read by the script and sent as `X-CSRF-Token` — the header the existing
    // middleware already accepts. Nothing about the token is composed in JavaScript.
?>
    <div class="a2t-review-token" data-a2t-review-token hidden><?= $csrfField ?></div>
    <?php
    // The three icons the correction page puts beside a bubble: the handle, the pencil, and the clock
    // on a message that has been corrected before. Rendered once and cloned per turn, from the same
    // constants that page draws, so no SVG path is written out a second time in JavaScript.
    //
    // Each label is left empty and filled per turn: "Move to Customer" and "Move to Agent" are the
    // same icon saying two different things, and which one it says is the server's decision.
?>
    <div class="a2t-iconbank" data-a2t-iconbank hidden>
        <template data-a2t-icon="move"><?= AudioToTextIcons::svg(AudioToTextIcons::GRIP, '') ?></template>
        <template data-a2t-icon="edit"><?= AudioToTextIcons::svg(AudioToTextIcons::PENCIL, '') ?></template>
        <template data-a2t-icon="history"><?= AudioToTextIcons::svg(AudioToTextIcons::CLOCK, '') ?></template>
        <?php
        // And the transport for the browser's own voice, from the same constants for the same reason.
?>
        <template data-a2t-icon="play"><?= AudioToTextIcons::svg(AudioToTextIcons::PLAY, '') ?></template>
        <template data-a2t-icon="pause"><?= AudioToTextIcons::svg(AudioToTextIcons::PAUSE, '') ?></template>
        <template data-a2t-icon="resume"><?= AudioToTextIcons::svg(AudioToTextIcons::RESUME, '') ?></template>
        <template data-a2t-icon="stop"><?= AudioToTextIcons::svg(AudioToTextIcons::STOP, '') ?></template>
    </div>
    <?php
    // Where the revision dialogs land. Fetched from the same partial the correction page renders
    // inline, after every read, so a message corrected a moment ago has its clock icon and its
    // revision without the reader reloading anything.
?>
    <div class="a2t-history-dialogs" data-a2t-history-host></div>
    <?php
    // `a2t-review` is the correction page's own scope, borrowed deliberately: it is what stacks the
    // controls under a bubble, puts the icons in the margin on the speaker's side and tints a
    // published Agent turn green. `a2t-chat` is **not** borrowed — `.app:has(.a2t-chat)` pins the
    // whole shell to 100vh, which is right for a page that is only a conversation and wrong for a
    // table with a dialog over it.
?>
    <div class="source-modal__body a2t-review" data-a2t-review-body hidden>
        <?php
        // Three ways to hear this recording, filled per recording when the dialog opens: the file that
        // was uploaded, the audio this application generated from the transcript, and the browser
        // reading the transcript aloud itself. They are kept apart because they are different things —
        // one is evidence, one costs money, and one is a convenience that leaves nothing behind.
?>
        <div class="a2t-listen" data-a2t-listen hidden></div>
        <div class="a2t-mnotice" data-a2t-review-notice></div>
        <div class="a2t-chat__scroll a2t-dialog-scroll" data-a2t-review-scroll></div>
    </div>
</dialog>

<?php
// Asking before transcribing.
//
// Pressing Transcribe used to start the work on the press. That is the right behaviour for something
// cheap and reversible, and transcription is neither: it spends CPU or money, it cannot be called back
// once a provider has the audio, and the button sits in a row of three identical ones where the wrong
// click is easy. So the press now opens this, and the work starts on a second, deliberate press.
//
// **Opening it does nothing.** No request is made, no status moves, and closing it leaves the recording
// exactly as it was. Everything the dialog says is read from the button that opened it, so it describes
// the recording actually clicked rather than whichever was looked at last.
//
// Empty and filled on demand, like the three dialogs above it, so the page carries one of these however
// many recordings it lists.
?>
<dialog class="source-modal a2t-confirm-dialog" id="a2t-transcribe-dialog" data-a2t-dialog
        aria-labelledby="a2t-transcribe-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-transcribe-title">Transcribe this audio?</h2>
            <p class="source-modal__meta">
                This will convert the selected recording to text using the currently configured
                transcription provider.
            </p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
            title="Close" aria-label="Close">&times;</button>
    </div>

    <div class="source-modal__body">
        <?php
        // What is about to happen, to what. Named in the client's own words — the recording's column
        // heading, not a channel code — so the line reads as the row it came from.
?>
        <dl class="a2t-confirm__facts">
            <div class="a2t-confirm__fact" data-a2t-confirm-order-row>
                <dt>Order ID</dt>
                <dd data-a2t-confirm-order></dd>
            </div>
            <div class="a2t-confirm__fact">
                <dt>Recording</dt>
                <dd data-a2t-confirm-recording></dd>
            </div>
            <div class="a2t-confirm__fact" data-a2t-confirm-duration-row>
                <dt>Duration</dt>
                <dd data-a2t-confirm-duration></dd>
            </div>
            <div class="a2t-confirm__fact" data-a2t-confirm-provider-row>
                <dt>Provider</dt>
                <dd data-a2t-confirm-provider></dd>
            </div>
        </dl>

        <?php
        // Only ever shown after a refusal, and worded for the person rather than for a log. A failure
        // here leaves the recording exactly where it was, which is why the dialog stays open: there is
        // something to try again.
?>
        <p class="field__error" data-a2t-confirm-error hidden></p>
    </div>

    <div class="source-modal__foot a2t-confirm__actions">
        <button class="btn btn--secondary" type="button" data-a2t-dialog-close>Cancel</button>
        <button class="btn btn--primary" type="button" data-a2t-confirm-transcribe>Start transcription</button>
    </div>
</dialog>

<dialog class="source-modal a2t-transcript-dialog" id="a2t-transcript-dialog" data-a2t-dialog
        aria-labelledby="a2t-transcript-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-transcript-title">Original transcript</h2>
            <p class="source-modal__meta" data-a2t-transcript-meta></p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <div class="a2t-tabs" role="tablist" data-a2t-transcript-tabs hidden></div>
    <p class="source-modal__status" data-a2t-transcript-status hidden></p>
    <?php
    // The same bubbles, without `a2t-review`: nothing here can be corrected, so there is no margin to
    // reserve for controls and no role to tint. That is the read-only conversation page's own layout.
?>
    <div class="source-modal__body" data-a2t-transcript-body hidden>
        <div class="a2t-chat__scroll a2t-dialog-scroll" data-a2t-transcript-scroll></div>
    </div>
</dialog>

<?php
// Update Audio — one recording, opened from its own Details modal.
//
// Deliberately NOT the Manage Audio dialog. That one is the whole order: three sections, every version
// of each, and a type to choose. Opened from a transcript an administrator has just decided is wrong,
// it asks them to find the recording they were already looking at and say again which one it was.
//
// So the type is a LABEL here, never a control. It is carried as `replaces` — this recording's own job
// public id — and `ReplaceAction` derives the recording type from that job rather than from anything
// this form posts. A tampered body cannot file the upload under a different slot.
//
// The same endpoint, the same ingestion, the same validation and the same provider rule as every other
// upload. Nothing about replacement is restated here.
?>
<dialog class="source-modal a2t-update-dialog" id="a2t-update-dialog" data-a2t-dialog
        aria-labelledby="a2t-update-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-update-title">Update Audio</h2>
            <p class="source-modal__meta" data-a2t-update-meta></p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                aria-label="Close">&times;</button>
    </div>

    <p class="source-modal__status" data-a2t-update-status hidden></p>

    <form class="source-modal__body a2t-update" method="post" enctype="multipart/form-data"
          data-a2t-update-form>
        <?= $csrf->hiddenInput() ?>
        <?php // Named by the server when the dialog opens. Never a control the reader can change.?>
        <input type="hidden" name="replaces" data-a2t-update-replaces value="">

        <?php
        // The same three-class row the other confirmation dialogs use for a key and its value, rather
        // than a fourth arrangement of the same thing. Facts, not fields: the recording being updated is
        // decided by the button that opened this dialog, and none of it is for the reader to change.
?>
        <div class="a2t-confirm__row">
            <span class="a2t-confirm__key">Store</span>
            <span class="a2t-confirm__value"><?= Html::encode($store->name) ?></span>
        </div>
        <div class="a2t-confirm__row">
            <span class="a2t-confirm__key">Order</span>
            <span class="a2t-confirm__value" data-a2t-update-order></span>
        </div>
        <div class="a2t-confirm__row">
            <span class="a2t-confirm__key">Recording</span>
            <span class="a2t-confirm__value" data-a2t-update-type></span>
        </div>
        <div class="a2t-confirm__row">
            <span class="a2t-confirm__key">Current file</span>
            <span class="a2t-confirm__value" data-a2t-update-current></span>
        </div>

        <div class="field">
            <label class="field__label" for="a2t-update-file">New audio file</label>
            <input class="field__control" type="file" id="a2t-update-file" name="audio"
                   accept="audio/*" required>
        </div>

        <?php
        // The same two closures the page's own upload form uses, so the three rules — which engines
        // exist, which this machine can run, and whether AI audio may be asked for — are stated once in
        // this application rather than restated for a second form.
        //
        // The provider starts on this server's default rather than on the recording being replaced's,
        // because the dialog is rendered with the page and has no one recording in mind. `UploadOptions`
        // falls back to the replaced recording's own engine when the field posts nothing, and re-checks
        // that the chosen one can actually run.
?>
        <?php
    // Hidden for a Mix / Common recording, which is never converted to text — so an engine for its
    // transcript and a reading of that transcript are both settings for something that will not happen.
    // The server answers the same either way: `ReplaceAction` goes through the one ingestion seam, and
    // the policy there refuses a mixed recording whatever the form posts.
?>
        <div data-a2t-update-transcription>
            <?= $providerField('a2t-update-provider') ?>
            <?= $aiAudioField('a2t-update-ai-audio') ?>

            <p class="field__hint">
                Replacing this recording starts a new transcription. The recording it replaces stays in
                use, and keeps its transcript, corrections and generated audio, until the new one
                finishes.
            </p>
        </div>

        <p class="field__hint" data-a2t-update-audio-only hidden>
            This is the mixed recording of the call, so it is stored as the playable original and is not
            converted to text. Replacing it changes the audio and nothing else — the recording it
            replaces stays in use, and any transcript it already has is left exactly as it is.
        </p>

        <div class="a2t-confirm__actions">
            <button class="btn btn--sm" type="button" data-a2t-dialog-close>Cancel</button>
            <button class="btn btn--primary btn--sm" type="submit" data-a2t-update-submit>Update Audio</button>
        </div>
    </form>

    <?php
    // The one progress card. Same file, same hooks and same stage list as the Details dialog's and the
    // AI-audio one — see the partial for why there is only ever one of these.
?>
    <?= $this->render(AudioToTextViews::processingCard(), [
        'title' => 'Update Audio Progress',
        'cardAttrs' => ['data-a2t-processing' => 'update'],
        'badgeAttrs' => ['data-a2t-processing-badge' => true],
        'badgeText' => 'Starting',
        'steps' => $transcriptionSteps(),
        'errorAttrs' => ['data-a2t-processing-error' => true],
        'layout' => 'overall',
        'meta' => true,
        'note' => true,
        'closable' => true,
        'extra' => '',
    ]) ?>
</dialog>

<?php
// Generate / Regenerate AI audio — one recording, one question.
//
// Small on purpose. The big AI-audio page exists and is unchanged; this is the confirmation an
// administrator gets when they press the button inside a transcript they are already reading, and it
// needs to say three things: which recording, which transcript it will be read from, and what the audio
// on disk is right now.
//
// The button is offered even when the audio is already current. That is not an invitation to pay twice:
// the dialog says so, disables its own confirm, and `TtsGenerationService::enqueue()` still answers
// AlreadyCurrent for a matching digest and queues nothing. Offering it and explaining is a better answer
// than hiding it and leaving the reader to wonder.
?>
<dialog class="source-modal a2t-tts-confirm-dialog" id="a2t-tts-confirm-dialog" data-a2t-dialog
        aria-labelledby="a2t-tts-confirm-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-tts-confirm-title" data-a2t-tts-confirm-title>
                Generate AI Audio
            </h2>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                aria-label="Close">&times;</button>
    </div>

    <p class="source-modal__status" data-a2t-tts-confirm-status hidden></p>

    <div class="source-modal__body">
        <div class="a2t-confirm__row">
            <span class="a2t-confirm__key">Recording</span>
            <span class="a2t-confirm__value" data-a2t-tts-confirm-recording></span>
        </div>
        <?php
        // Which transcript gets read aloud, in the words `EffectiveConversationReader` decides it by:
        // a corrected transcript if this recording has one, otherwise the machine's. Shown because it is
        // the one thing about a generation an administrator cannot see from the button.
?>
        <div class="a2t-confirm__row">
            <span class="a2t-confirm__key">Reads</span>
            <span class="a2t-confirm__value" data-a2t-tts-confirm-source></span>
        </div>
        <div class="a2t-confirm__row">
            <span class="a2t-confirm__key">Existing audio</span>
            <span class="a2t-confirm__value" data-a2t-tts-confirm-state></span>
        </div>

        <p class="field__hint" data-a2t-tts-confirm-note></p>

        <div class="a2t-confirm__actions">
            <button class="btn btn--sm" type="button" data-a2t-dialog-close>Cancel</button>
            <button class="btn btn--primary btn--sm" type="button" data-a2t-tts-confirm-submit>
                Generate
            </button>
        </div>
    </div>

    <?php
    // The one progress card. Same file, same hooks and same stage list as the Details dialog's and the
    // AI-audio one — see the partial for why there is only ever one of these.
?>
    <?= $this->render(AudioToTextViews::processingCard(), [
        'title' => 'AI Audio Progress',
        'cardAttrs' => ['data-a2t-processing' => 'tts'],
        'badgeAttrs' => ['data-a2t-processing-badge' => true],
        'badgeText' => 'Starting',
        'steps' => [
            $progressStep('01', 'ACCEPTED', 'Request', 'Generation request accepted'),
            $progressStep('02', 'GENERATING', 'Generate audio', 'Creating AI audio from the transcript'),
        ],
        'errorAttrs' => ['data-a2t-processing-error' => true],
        'layout' => 'overall',
        'meta' => true,
        'note' => true,
        'closable' => true,
        'extra' => '',
    ]) ?>
</dialog>

<?php
// Manage Audio. One section per kind of recording, each listing its current version and everything it
// superseded, with the replacement upload inside the section it belongs to.
//
// The form is a real multipart POST carrying the page's CSRF field, submitted by `fetch` so the reader
// stays on the store page — the same shape the Generate form uses. Nothing about which recording is
// being replaced is decided here: the section supplies the recording type, and the server re-checks it
// against the group it resolved.
?>
<dialog class="source-modal a2t-manage-dialog" id="a2t-manage-dialog" data-a2t-dialog
        aria-labelledby="a2t-manage-title">
    <div class="source-modal__head">
        <div>
            <?php
            // Renamed by the script when the dialog is opened from one column's "+ Add audio": it then
            // shows that channel alone, and calling that "Manage Audio" would promise the other two.
?>
            <h2 class="source-modal__title" id="a2t-manage-title" data-a2t-manage-title>Manage Audio</h2>
            <p class="source-modal__meta" data-a2t-manage-meta></p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <p class="source-modal__status" data-a2t-manage-status hidden></p>
    <div class="source-modal__body" data-a2t-manage-body hidden>
        <div class="a2t-manage" data-a2t-manage-slots></div>
    </div>
    <div class="a2t-manage-token" data-a2t-manage-token hidden><?= $csrfField ?></div>
</dialog>

<dialog class="source-modal a2t-tts-dialog" id="a2t-tts-dialog" data-a2t-dialog
        aria-labelledby="a2t-tts-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-tts-title">Generate Text to Audio</h2>
            <p class="source-modal__meta" data-a2t-tts-meta></p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <p class="source-modal__status" data-a2t-tts-status hidden></p>
    <?php
// A real form posting to the existing generate route. The action, the output type and the hash all
// come from the server's own answer about this group — the browser never decides which job or
// which output type a choice means, because the UI's Caller is not the column's CUSTOMER.
?>
    <form class="source-modal__body" method="post" action="" data-a2t-tts-form hidden>
        <?= $csrfField ?>
        <input type="hidden" name="output_type" value="" data-a2t-tts-output>
        <input type="hidden" name="expected_hash" value="" data-a2t-tts-hash>
        <div class="a2t-tts-options" data-a2t-tts-options></div>
        <button class="btn btn--primary" type="submit" data-a2t-tts-submit disabled>Generate</button>
    </form>
</dialog>

<?php
// The same two confirmations the correction page shows, from the same partial. The dialog submits
// them by fetch instead of following the redirect; the fields, the token and the version are
// identical, which is what makes this the same operation rather than a second one that resembles it.
?>
<?= $this->render(AudioToTextViews::reviewConfirm(), [
    'csrfField' => $csrfField,
    'version' => null,
]) ?>

<dialog class="source-modal a2t-history-dialog" id="a2t-history-dialog" data-a2t-dialog
        aria-labelledby="a2t-history-title">
    <div class="source-modal__head">
        <h2 class="source-modal__title" id="a2t-history-title">Earlier recordings</h2>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <div class="source-modal__body" data-a2t-history-body></div>
</dialog>
