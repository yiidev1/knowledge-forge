<?php

declare(strict_types=1);

use App\AudioToText\Domain\AssignedTopic;
use App\AudioToText\Domain\MirroredOrder;
use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\ConversationCapabilities;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Domain\OrderId;
use App\AudioToText\Domain\OrderVerificationStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\StoreOrderGroup;
use App\Shared\Order58\DemoLinkStatus;
use App\Shared\Order58\DemoOrderUrl;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Domain\WorkerStatusView;
use App\AudioToText\Web\AudioToTextIcons;
use App\AudioToText\Web\AudioToTextViews;
use App\AudioToText\Web\OrderTesting\OrderTestingAsset;
use App\AudioToText\Web\OrderTesting\OrderTestingRoute;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Audio\RecordingAcquisitionState;
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
 * @var array<string, string> $routes the addresses this surface builds its links from; see
 *      StoreAction::surfaceRoutes(). Practice Orders renders this same template with its own.
 * @var array<string, int> $demoOrderCounts demo orders imported per order id, keyed the same way
 * @var OrderGroupFilter|null $groupFilter which orders were fetched; null is all of them, which is what
 *      Order Testing always passes. Practice Orders passes what its `?topic=` asked for.
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
 * @var ConversationCapabilities|null $capabilities what the reader may change; absent means everything
 * @var AppTimeZone $appTimeZone
 * @var TranscriptionProvider $provider currently selected, and what all recording cards preselect
 * @var list<TranscriptionProvider> $providerChoices every provider, usable or not
 * @var array<string, bool> $providerUsable storage value => can this server run it (local check only)
 * @var TranscriptionProvider $globalDefault the stored setting, reported as-is and never rewritten here
 * @var bool $ttsConfigured whether clean AI audio can be generated on this server at all
 */

$assetManager->register(OrderTestingAsset::class);

$this->setTitle($store->name . ' — manage orders');
// Its own trail, back to its own landing. The audio page's breadcrumb returns to the Order58 picker,
// whose links go to `/audio-to-text/...`; following it from here would move the reader onto the surface
// this one exists to keep them off.
$this->setParameter('breadcrumbs', [
    ['label' => $routes['pageLabel'], 'route' => $routes['page']],
    ['label' => $store->name],
]);

$csrfField = (string) $csrf->hiddenInput();

// One per page, not one per row: every Demo URL form on this table posts to the same store-scoped
// address and names its own order in a hidden field.
// Practice Orders renders this same template with a topic on every row and two extra columns. Order
// Testing passes none of this, so it is defaulted off here and the markup below is unchanged for it.
$practiceMode = ($practiceMode ?? false) === true;

if ($practiceMode) {
    // Only this surface needs the selection script and the two columns' styling. Registering it here
    // rather than in the asset above keeps the Order Testing page's payload exactly as it was.
    $assetManager->register(\App\Practice\Web\PracticeAsset::class);
}

// Annotated where they are defaulted rather than cast at each of the eight places they are read: the
// `??` is what loses the type, so this is where it is given back.
/** @var array<string, AssignedTopic> $topicAssignments keyed by the order id each row shows */
$topicAssignments = $topicAssignments ?? [];

/*
 * Whether the upload form asks which side of the call a file holds.
 *
 * Defaulted rather than required, so a surface that has not been taught about it keeps the choice it
 * has always had. The server pins the value either way.
 *
 * @var bool $canChooseRecordingType
 */
$canChooseRecordingType = $canChooseRecordingType ?? true;


/** @var list<AssignedTopic> $assignableTopics active topics, the only ones that may be chosen */
$assignableTopics = $assignableTopics ?? [];

$demoUrlAction = $urlGenerator->generate($routes['demoUrl'], ['sourceId' => $store->sourceId]);
$storeUrl = $urlGenerator->generate($routes['store'], ['sourceId' => $store->sourceId]);
// One endpoint for the whole page. The attribute that uses it is rendered only while something is
// still being downloaded, so an idle page never asks.
$arrivingUrl = $urlGenerator->generate($routes['arriving'], ['sourceId' => $store->sourceId]);
/**
 * What the reader may change. Absent means everything, so Order Testing and the audio store page are
 * rendered exactly as they were — their markup is the regression test for that.
 *
 * Every control below asks its own question, and a control whose answer is false is never emitted:
 * no element, no `data-` hook, nothing for the script to bind to. The agent routes do not mount the
 * endpoints those controls would call either, so the page and the server agree.
 *
 * @var ConversationCapabilities $capabilities
 */
$capabilities = $capabilities ?? ConversationCapabilities::full();

// Reading which topic an order carries is not assigning one. The column, its details dialog and the
// filter travel with `$practiceMode`; the checkbox, the buttons, the bulk toolbar and the picker
// travel with this.
$canAssignTopics = $practiceMode && $capabilities->canAssignTopics;

// Verification is Order Testing's own. `$practiceMode` is true on the read-only practice surfaces as
// well, and `$capabilities` is permissive on the Audio-to-Text store page, so neither answers this on
// its own: the column exists only where both are true, which is this one page.
$canVerifyOrders = $practiceMode && $capabilities->canVerifyOrders;

// What agents listened to. The same two conditions as Verification and for the same reason — it is a
// statement about an order, and it belongs on the page where orders are managed — but a different
// question, so a flag of its own rather than a second use of that one.
$canViewAgentEvents = $practiceMode && $capabilities->canViewAgentEvents;
$agentPlaybackUrl = $canViewAgentEvents
    ? $urlGenerator->generate(OrderTestingRoute::PLAYBACK_SUMMARY, ['sourceId' => $store->sourceId])
    : '';
/** @var array<string, OrderVerificationStatus> $orderVerifications */
$orderVerifications = $orderVerifications ?? [];

/*
 * Seq and View Receipt come from one query and are two separate decisions.
 *
 * They shipped together and were gated together, which quietly meant the practice surfaces could
 * have neither. Seq is one more fact about an order a reader is already looking at; a receipt spends
 * a call on a third party and shows a customer's printed order. The practice surfaces get the first
 * and not the second, so the flags are separate and the column tests say which is which.
 */
$canViewOrderSeq = $practiceMode && $capabilities->canViewOrderSeq;
$canViewOrderReceipts = $practiceMode && $capabilities->canViewOrderReceipts;

// Whether the conversation may be read as words. Audio is not governed by this and never is.
$canReadConversation = $capabilities->canReadConversation;

/**
 * The mirrored order behind each row, keyed by the order id the row shows.
 *
 * A **missing key means there is no such order under this store** — which is a different thing from an
 * order with no Seq, and the two cells say so differently.
 *
 * @var array<string, MirroredOrder> $mirroredOrders
 */
$mirroredOrders = $mirroredOrders ?? [];

// The store being active is a fact about the store; being allowed to upload is a fact about the
// reader. Both must hold, and ANDing them means the upload dialog, both Add Audio buttons and the
// empty-state call to action all disappear together for a reader who may not upload.
//
// The two are kept apart because the notice below needs the store's half on its own. "Order58 reports
// this store as inactive, so no new recordings can be uploaded" answers a question only somebody who
// *would otherwise upload* is asking; shown to a reader who can never upload anything it reads as a
// problem with the page. Collapsing both facts into one flag put that notice on every agent page,
// active store or not.
$storeAcceptsUploads = $canUpload;
$canUpload = $storeAcceptsUploads && $capabilities->canUploadAudio;

// Resolved once, from the filter the Action actually queried with rather than from the query string
// again: the picker's ticked boxes, the pager's links and the hidden fields that survive an assignment
// all read this, so none of them can claim a filter the rows were not fetched under.
//
// A filter may exist and still narrow nothing — the practice surfaces always pass one, because they
// restrict the listing to orders that can actually be practised. `$topicChoices` is what the READER
// chose; everything the control and the links are built from reads that, not the filter's existence.
/** @var list<string> $topicChoices */
$topicChoices = $groupFilter !== null && $groupFilter->narrowsTopics() ? $groupFilter->queryValues() : [];
$hasTopicFilter = $topicChoices !== [];

$pageQuery = [];

foreach ($topicChoices as $index => $choice) {
    // `topics[]` repeated, which is what the picker submits and what the parser reads back.
    $pageQuery[OrderGroupFilter::PARAM . '[' . $index . ']'] = $choice;
}

// Unchanged for an unfiltered page: no filter means no query array, so page 1 is the bare store URL and
// page N is `?page=N`, exactly as before.
$pageUrl = static function (int $p) use ($storeUrl, $pageQuery): string {
    $query = $p > 1 ? $pageQuery + ['page' => (string) $p] : $pageQuery;

    if ($query === []) {
        return $storeUrl;
    }

    // Built pair by pair rather than with http_build_query: the keys already carry their `[0]`
    // suffixes and that function would encode the brackets, which PHP then reads back as one
    // literally-named parameter instead of a list.
    $pairs = [];

    foreach ($query as $key => $value) {
        $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
    }

    return $storeUrl . '?' . implode('&', $pairs);
};

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
// the browser from an id.
//
// **From the surface's own map, not from the audio module's constants.** These five are job-scoped —
// the fragment the conversation dialog fetches, the recording it plays, the progress it polls — and
// each surface mounts them at its own path. Printing the administrator address on a page an agent is
// reading would hand the browser a URL that realm cannot reach, and the dialog would simply never
// fill. That is invisible to a markup test and invisible to an unauthenticated one. A deployment prefix, the router's own escaping and the shape of each route
// are all things this side knows and the script deliberately does not — the same rule the upload
// flow already follows with `data-a2t-done`.
$originalUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    $routes['jobOriginalFile'],
    ['publicId' => $jobPublicId],
);
$fragmentUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    $routes['jobFragment'],
    ['publicId' => $jobPublicId],
);
// Where a recording that has never been transcribed is asked about. A POST, and it names exactly one
// recording: a call's three channels are three separate recordings and each is asked for on its own.
$transcribeUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    $routes['jobTranscribe'],
    ['publicId' => $jobPublicId],
);
// The polling endpoint, for a recording that is still being transcribed. The same one the Update
// dialog follows — there is one status endpoint and every surface that shows progress reads it.
$statusUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    $routes['jobStatus'],
    ['publicId' => $jobPublicId],
);
$fullReviewUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    $routes['jobReview'],
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
    $statusUrl,
    // What this reader may change. Every control below asks it; one that answers no is not emitted.
    $capabilities
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
            . ($capabilities->canOpenFullEditor
                ? ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
                : '')
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
$channelIndicator = static function (
    StoreRecordingSlot $slot,
    /**
     * Whether the reader is shown this channel's name beside the marker.
     *
     * False in the orders table, where one column answers one question — can this order's Mix
     * recording be played? — and "Customer ✓ Agent ✓" underneath answered a different one. The
     * hidden half is emitted either way, because it is not decoration: the conversation dialog
     * builds its tab strip by scanning the row for it, so dropping it would cost the dialog its
     * Customer and Agent tabs. Data for the script, nothing for the reader.
     */
    bool $withLabel = true,
) use (
    $fragmentUrl,
    $fullReviewUrl,
    $capabilities,
): string {
    $marker = '<span hidden'
        . ' data-a2t-current-channel="' . $slot->channelRank() . '"'
        . ' data-a2t-details="' . Html::encode($fragmentUrl($slot->jobPublicId)) . '"'
        . ($capabilities->canOpenFullEditor
            ? ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
            : '')
        . ' data-a2t-details-label="' . Html::encode($slot->label()) . '"></span>';

    if (!$withLabel) {
        return $marker;
    }

    // `aria-hidden` on the tick: a reader using a screen reader is told the channel's name, which is
    // the information. Read aloud, the glyph would be pronounced as a word.
    return $marker
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
    $capabilities,
    $slotCellInner,
    $channelIndicator,
    $appTimeZone,
    $groupUrl,
    $statusUrl,
    $transcribeUrl,
    $globalDefault,
    // The surface's own addresses: this closure builds the Manage-audio link, and Practice Orders
    // renders the same template with a different map. A template is function scope, so it has to be
    // named here rather than simply being in the file.
    $routes
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
        if (
            $emptyType === null || $group === null || $group->orderId === null || $group->isLegacySeparate()
            || !$capabilities->canReplaceAudio
        ) {
            return '<span class="util-muted">&mdash;</span>';
        }

        return '<button class="a2t-slot__add" type="button"'
            . ' data-a2t-manage="' . Html::encode($groupUrl($routes['recordings'], $group->key)) . '"'
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
            . ($capabilities->canOpenFullEditor
                ? ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
                : '')
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">Details</button>';
    } elseif ($slot->isReadyForTranscription()) {
        // Audio and nothing else. No Details, because there is no transcript to show — opening an empty
        // correction editor would be pretending there is. One action, and it is the only one that makes
        // sense: ask for the text.
        $html .= '<span class="a2t-slot__note a2t-slot__note--ready">Ready for transcription</span>';

        // The one action this state offers is a write. A reader who may not transcribe is told the
        // recording is waiting and offered nothing — rather than a button the server would refuse.
        if (!$capabilities->canTranscribe) {
            return $html . '</div>';
        }

        $html .= '<button class="a2t-slot__link a2t-slot__link--go" type="button"'
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
            . ($capabilities->canOpenFullEditor
                ? ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
                : '')
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
 * When this call happened and when it reached us, as one line for a tooltip.
 *
 * The two facts the Order column used to print under every number. Kept, because somebody does
 * occasionally need them and the data is right here — moved onto the number's own `title` so they
 * cost a hover instead of two lines of row height on every row in the table.
 *
 * The call time is the provider's own string, printed exactly as sent and with NO zone label: it
 * carries none, and appending one would be inventing a claim. The import instant is this
 * application's own, so it gets the business timezone every other timestamp here is formatted in.
 */
$orderWhen = static function (StoreOrderGroup $group) use ($appTimeZone): string {
    $parts = [];

    if ($group->callTimeRaw !== null) {
        $parts[] = 'Call time ' . $group->callTimeRaw;
    }

    $parts[] = ($group->callTimeRaw === null ? 'Added ' : 'Downloaded ')
        . $appTimeZone->format($group->latestActivityAt, 'M j, Y g:i A');

    return implode(' · ', $parts);
};

/**
 * One order's **Mix / Common** recording, and nothing else.
 *
 * ## One column, one question
 *
 * This cell used to be "Recording / Conversation": a play control, a View conversation button and a
 * caption naming all three channels with ticks. Four facts in a table column that has room for one,
 * and the one a reader actually wants is whether they can listen to this call right now. So the cell
 * answers exactly that, about exactly one recording.
 *
 * **Mix only, and never a substitute.** The old play control took the first of Mixed, Caller or Callee
 * that happened to have audio, so a row could be playing one side of a call while looking identical to
 * a row playing both. Customer and Agent recordings are still stored, still transcribed and still
 * reachable through the conversation dialog; they are simply not offered here under another name.
 *
 * ## The five states, and why the secondary channels cannot change them
 *
 * A call is fetched from the provider one channel at a time, so Mix can be in hand while Customer is
 * still downloading, and Customer can fail while Mix plays perfectly. This reads **only** the Mix
 * channel's own acquisition state, which is what keeps a secondary failure from greying out a working
 * player.
 *
 * | State | Shown |
 * |---|---|
 * | Mix audio in hand | the play control, with its duration |
 * | Mix still arriving | its live label, updated in place by the arriving poller |
 * | Mix download failed | a failed state, in the provider-neutral words the enum already uses |
 * | Provider has no Mix | "No Mix audio" — nothing went wrong, there is simply none |
 * | Mix existed, file gone | "Mix audio not stored", which is retention, not a failure |
 *
 * Transcription status is not consulted anywhere in here. A recording that has never been through a
 * provider is still a recording, and it plays.
 *
 * ## What is still emitted, invisibly
 *
 * One hidden marker per current channel. The conversation dialog builds its tab strip by scanning the
 * row for them, so without them the dialog would open on Mix alone and the Customer and Agent tabs —
 * and the transcripts behind them — would be unreachable. They render nothing.
 */
$conversationCell = static function (StoreOrderGroup $group) use (
    $clock,
    $originalUrl,
    $channelIndicator
): string {
    $html = '<div class="ot-rec">';

    $mix = $group->mixed;
    // The acquisition reader keys its channels by the lower-cased recording type, the same spelling
    // the empty-slot note uses — so the arriving poller updates this cell in place by the same
    // selector it has always used.
    $mixChannel = strtolower(RecordingType::Mixed->value);
    $arriving = $group->arrivingFor($mixChannel);

    if ($mix !== null && $mix->hasOriginalAudio) {
        // In hand. Drawn even while its transcription is queued, running or absent entirely: this is
        // a play button, and none of that stops a file playing.
        $html .= '<button class="a2t-play" type="button"'
            . ' data-a2t-play="' . Html::encode($originalUrl($mix->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play the Mix recording of this call') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span>'
            . '<span class="a2t-play__time">' . Html::encode($clock($mix->durationSeconds)) . '</span>'
            . '</button>';
    } elseif ($arriving !== null && !$arriving->isSettled()) {
        // Still coming. `data-a2t-arriving-channel` is what the poller already rewrites as each state
        // changes, so this line follows the download without the page reloading.
        $html .= '<span class="ot-rec__state ot-rec__state--arriving"'
            . ' data-a2t-arriving-channel="' . Html::encode($mixChannel) . '">'
            . Html::encode($arriving->label()) . ' Mix audio…</span>';
    } elseif ($arriving === RecordingAcquisitionState::Failed) {
        // The provider was asked and the request failed. The enum's own word for it, and no more: the
        // underlying reason is a provider response this page has no business reprinting.
        $html .= '<span class="ot-rec__state ot-rec__state--failed"'
            . ' data-a2t-arriving-channel="' . Html::encode($mixChannel) . '"'
            . ' title="' . Html::encode('The Mix recording could not be downloaded from the provider.') . '">'
            . 'Mix audio failed</span>';
    } elseif ($mix !== null) {
        // The recording is known but its file is not here — retention took it, or it was never kept.
        // Distinct from both "none exists" and "the download failed", because it is neither.
        $html .= '<span class="ot-rec__state ot-rec__state--gone">Mix audio not stored</span>';
    } elseif ($group->primaries() === []) {
        /*
         * No recording of any kind. A dash, not a sentence.
         *
         * This is an ordinary row of a case-order-primary listing: the order was imported and nothing
         * has been recorded against it yet. "No Mix audio" would be answering a question nobody asked
         * — it distinguishes the mixed channel from the other two, and this row has no channels at
         * all. The dash is the same answer the Demo orders column already gives for "none yet", and
         * the reason is on the `title` for anyone who wonders.
         */
        $html .= '<span class="ot-rec__state ot-rec__state--none"'
            . ' title="' . Html::encode('No call recording has been imported for this order.') . '">'
            . '&mdash;</span>';
    } else {
        // Nothing to play and nothing went wrong, but this row DOES have recordings — the other
        // channels. Naming the missing one is the useful answer here, and is what it has always said.
        $html .= '<span class="ot-rec__state ot-rec__state--none">No Mix audio</span>';
    }

    // Invisible, and load-bearing — see the docblock. One per CURRENT channel, so a side uploaded
    // twice contributes one tab rather than two with the same name.
    foreach ($group->primaries() as $slot) {
        if ($slot->isReviewable() || $slot->isAudioOnly()) {
            $html .= $channelIndicator($slot, false);
        }
    }

    return $html . '</div>';
};

?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($store->name) ?></h1>
        <p class="page-header__subtitle">
            Manage this store's orders, recordings and conversations. Call recordings are grouped
            by the order they belong to.
        </p>
    </div>
    <div class="page-header__actions">
        <?php
// The same button, the same endpoint and the same importer as the picker's — the only
// difference is the `return` field, which brings the operator back to this store rather than
// to the picker. The import itself stays global: one configured directory, and the store is
// read from inside each document, so there is nothing here to scope it by.
//
// `data-busy-label` is the generic double-submit guard in admin.js. The real protection is the
// lock on the server, which also holds across two tabs and against the console command.
?>
        <?php if ($capabilities->canSyncDemoOrders): ?>
            <form method="post"
                action="<?= Html::encode($urlGenerator->generate($routes['syncDemo'])) ?>">
                <?= $csrfField ?>
                <input type="hidden" name="return" value="<?= Html::encode($routes['store']) ?>">
                <input type="hidden" name="sourceId" value="<?= Html::encode((string) $store->sourceId) ?>">
                <button class="btn btn--secondary" type="submit"
                    data-busy-label="Syncing…">Sync demo orders</button>
            </form>
        <?php endif; ?>
        <?php // Order Testing's own list, not the Order58 picker, whose links go to the audio page.
?>
        <a class="btn" href="<?= Html::encode($urlGenerator->generate($routes['page'])) ?>">All stores</a>
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

<?php
// Only for a reader who could otherwise upload. An inactive store is still perfectly practisable —
// last month's calls and the orders behind them are all still there — so for somebody who never
// uploads this is not a warning about anything they can act on.
?>
<?php if ($capabilities->canUploadAudio && !$storeAcceptsUploads): ?>
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

    <?php if ($practiceMode): ?>
        <?php
        // A plain GET form, outside the table and above the empty state so it is reachable even when
        // the filter has emptied the list. The filter lives in the address, which is what makes it
        // survive a reload, a bookmark and the pager without a line of script.
        //
        // `page` is deliberately not carried: narrowing the list renumbers it, and page four of a
        // one-page result is an empty table. Every submission starts at page one.
        //
        // **Checkboxes, not a multi-select.** A native `<select multiple>` needs ctrl-click to add a
        // second choice, loses the lot on a stray click, and cannot show what is ticked without being
        // opened. Checkboxes in a panel say what is chosen, add and remove one at a time, and work with
        // a keyboard and with no script at all.
        $offeredIds = [];

        foreach ($assignableTopics as $offeredTopic) {
            $offeredIds[] = $offeredTopic->id;
        }

        // Topics the address names that the picker no longer offers — retired since the link was made.
        // They are still what the rows were fetched under, so each gets a box of its own rather than
        // being silently dropped, which would have the control contradict the table.
        $retiredChoices = [];

        foreach ($topicChoices as $choice) {
            if ($choice !== OrderGroupFilter::UNASSIGNED && !in_array((int) $choice, $offeredIds, true)) {
                $retiredChoices[] = $choice;
            }
        }

        $chosen = static fn(string $value): bool => in_array($value, $topicChoices, true);
        $summary = $topicChoices === []
            ? 'All topics'
            : count($topicChoices) . ' selected';
        ?>
        <form class="practice-filter" method="get" action="<?= Html::encode($storeUrl) ?>"
            data-practice-filter-form>
            <span class="practice-filter__label" id="practice-filter-label">Filter by topic</span>
            <?php
            // Open and shut by the script; rendered open-able without it, because a `<details>` is a
            // disclosure the browser already knows how to work. Nothing here navigates on change: the
            // list changes when Apply is pressed, and not before.
        ?>
            <details class="practice-filter__picker" data-practice-filter-picker>
                <summary class="practice-filter__summary" aria-describedby="practice-filter-label">
                    <span data-practice-filter-summary><?= Html::encode($summary) ?></span>
                </summary>
                <div class="practice-filter__panel">
                    <?php if (count($assignableTopics) > 8): ?>
                        <?php // Only where the list is long enough to need it.
                    ?>
                        <label class="util-visually-hidden" for="practice-filter-search">Find a topic</label>
                        <input class="field__control practice-filter__search" type="search"
                            id="practice-filter-search" placeholder="Find a topic"
                            data-practice-filter-search autocomplete="off">
                    <?php endif; ?>

                    <ul class="practice-filter__options">
                        <li class="practice-filter__option" data-practice-filter-row>
                            <label>
                                <input type="checkbox" name="<?= OrderGroupFilter::PARAM ?>[]"
                                    value="<?= OrderGroupFilter::UNASSIGNED ?>"
                                    data-practice-filter-choice
                                    <?= $chosen(OrderGroupFilter::UNASSIGNED) ? 'checked' : '' ?>>
                                <span>Not assigned</span>
                            </label>
                        </li>
                        <?php foreach ($assignableTopics as $topic): ?>
                            <li class="practice-filter__option" data-practice-filter-row>
                                <label>
                                    <input type="checkbox" name="<?= OrderGroupFilter::PARAM ?>[]"
                                        value="<?= $topic->id ?>" data-practice-filter-choice
                                        <?= $chosen((string) $topic->id) ? 'checked' : '' ?>>
                                    <span><?= Html::encode($topic->name) ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($retiredChoices as $retired): ?>
                            <li class="practice-filter__option" data-practice-filter-row>
                                <label>
                                    <input type="checkbox" name="<?= OrderGroupFilter::PARAM ?>[]"
                                        value="<?= Html::encode($retired) ?>" data-practice-filter-choice checked>
                                    <span class="util-muted">Topic #<?= Html::encode($retired) ?> — no longer offered</span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="field__hint practice-filter__hint">
                        An order matches if it carries <strong>any</strong> of the topics you tick.
                    </p>
                </div>
            </details>

            <button class="btn btn--secondary btn--sm" type="submit">Apply</button>
            <?php if ($hasTopicFilter): ?>
                <a class="btn btn--sm" href="<?= Html::encode($storeUrl) ?>">Clear</a>
            <?php endif; ?>
            <?php
        /*
         * How many orders the filter is currently showing, at the far end of the same row.
         *
         * It was only at the foot of the table, which is the wrong end: the number that tells you
         * whether a filter did what you meant belongs beside the filter. The sentence under the
         * table keeps its fuller wording — this is the headline, that is the detail.
         *
         * Said in words as well as a number, so it reads as an answer rather than as a count of
         * something unnamed.
         */
        ?>
            <span class="practice-filter__count">
                <?= Html::encode((string) $total) ?> order<?= $total === 1 ? '' : 's' ?>
            </span>
        </form>
    <?php endif; ?>

    <?php if ($groups === []): ?>
        <?php // A short sentence and the one action that changes it, rather than an empty table.
        ?>
        <div class="a2t-empty-state">
            <?php if ($practiceMode && $hasTopicFilter): ?>
                <?php
            // Not "no recordings": the store may well have plenty, and saying so would send the
            // reader looking for an upload problem that does not exist.
                ?>
                <p>No orders match this topic filter.</p>
                <a class="btn" href="<?= Html::encode($storeUrl) ?>">Show all orders</a>
            <?php else: ?>
                <p>No audio recordings yet.</p>
                <?php if ($canUpload): ?>
                    <a class="btn btn--primary" href="<?= Html::encode($storeUrl . '?upload=1') ?>"
                        data-a2t-open="a2t-upload-dialog">+ Add Audio</a>
                <?php endif; ?>
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
            <?php if ($canAssignTopics): ?>
                <?php
                // The form the row checkboxes belong to, by `form=` rather than by nesting: a form
                // cannot wrap table rows without breaking the table, and the checkboxes have to sit in
                // their own column.
                //
                // Hidden until something is selected. A toolbar that is always present but usually
                // disabled teaches a reader to ignore it.
                ?>
                <form id="practice-bulk-form" method="post" class="practice-bulk" hidden
                    data-practice-bulk
                    action="<?= Html::encode($urlGenerator->generate(
                        $routes['topicBulk'],
                        ['sourceId' => $store->sourceId],
                    )) ?>">
                    <?= $csrfField ?>
                    <?php foreach ($topicChoices as $choice): ?>
                        <?php // The filter to return to, as on the single-assign form below.
                        ?>
                        <input type="hidden" name="filter_topics[]" value="<?= Html::encode($choice) ?>">
                    <?php endforeach; ?>
                    <span class="practice-bulk__count" data-practice-count>0 selected</span>
                    <label class="util-visually-hidden" for="practice-bulk-topic">Topic</label>
                    <?php
                    // **"Not Selected" is not "unassign".** It is the absence of a choice, and it is the
                    // default so that pressing Bulk Set Topic without choosing cannot silently apply
                    // whichever topic happened to sort first. The button stays disabled until a real
                    // topic is picked, and the service refuses an empty id regardless.
                    //
                    // Taking a topic *off* an order is a different operation and lives where it belongs:
                    // the Unassign entry in that row's own dropdown.
                ?>
                    <select class="field__control practice-bulk__select" id="practice-bulk-topic"
                        name="topic_id" data-practice-bulk-topic>
                        <option value="" selected>Not Selected</option>
                        <?php foreach ($assignableTopics as $topic): ?>
                            <option value="<?= $topic->id ?>"><?= Html::encode($topic->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php
                // The script turns this into a confirmation; with no script it submits directly.
                // Either way the server is the only thing that decides what is written.
                ?>
                    <?php // Disabled until a topic is chosen, as well as when there are none to choose.
                ?>
                    <button class="btn btn--primary btn--sm" type="submit" data-practice-bulk-submit
                        disabled>Set Topic</button>
                    <?php
                /*
                 * The way out of a selection, beside the way to use one.
                 *
                 * Unticking twenty rows one at a time is the alternative, and select-all only
                 * clears a selection when every row happens to be in it. `type="button"` so it
                 * cannot submit the form it sits in.
                 */
                ?>
                    <button class="practice-bulk__clear" type="button" data-practice-clear>Clear</button>
                    <span class="practice-bulk__note">
                        <?= $assignableTopics === []
                        ? 'No active topics yet — create one in Topic Management.'
                        : 'Applies to the orders selected on this page.' ?>
                    </span>
                </form>
            <?php endif; ?>

            <table class="table a2t-table a2t-orders a2t-conversions ot-orders">
                <?php
                // Sized to content, with the one recording column taking the slack: a filename no
                // longer appears here, so nothing in this table has an unbounded length.
?>
                <colgroup>
                    <?php // A column, not a cell: its own class, so the width rule cannot be confused
    // with the rules that lay out what is inside the cells.
?>
                    <?php if ($canAssignTopics): ?>
                        <col class="practice-col-select"><?php endif; ?>
                    <col class="a2t-col-order">
                    <?php // Beside the order number, because it is the same order said another way:
?>
                    <?php // the provider's own printed number for it.
?>
                    <?php if ($canViewOrderSeq): ?>
                        <col class="ot-col-seq"><?php endif; ?>
                    <col class="a2t-col-conversation">
                    <?php
// No Status column. The question it answered — "is this order's audio ready?" —
// is now answered by the Recording cell itself, which shows a player when the Mix
// recording can be played and says why when it cannot. Two columns describing one
// readiness disagreed with each other the moment a secondary channel failed.
?>
                    <?php if ($canVerifyOrders): ?>
                        <col class="ot-col-verification"><?php endif; ?>
                    <col class="a2t-col-demo">
                    <col class="ot-col-demo-orders">
                    <?php
// Declared, and declared HERE. A colgroup one `col` short does not leave the last
// column unsized — it shifts every width one place to the left, so the topic's
// 21rem landed on Agent Events and Set Topic collapsed to nothing. The order of
// these must match the header row exactly.
?>
                    <?php
// No Agent Events column. What agents played is still recorded, still served by its own route and
// still rendered by the dialog further down this template — it is the COLUMN that is gone, because
// a full share of the table's width was being spent on a control most rows never need. Restoring it
// is the `<col>`, the `<th>` and the `<td>` that git holds, and nothing else.
?>
                    <?php if ($practiceMode): ?>
                        <col class="practice-col-topic"><?php endif; ?>
                    <?php // Last, because it leaves the application: everything to its left is this
?>
                    <?php // page's own work, and this one fetches the provider's printed receipt.
?>
                    <?php if ($canViewOrderReceipts): ?>
                        <col class="ot-col-receipt"><?php endif; ?>
                </colgroup>
                <thead>
                    <tr>
                        <?php if ($canAssignTopics): ?>
                            <?php
        // Select-all reaches the rows that were rendered and nothing else. There is
        // no server-side "everything matching the filter" selection, so the control
        // does not imply one.
                            ?>
                            <th class="practice-select">
                                <input type="checkbox" data-practice-select-all
                                    aria-label="Select all orders on this page">
                            </th>
                        <?php endif; ?>
                        <th>Order No#</th>
                        <?php if ($canViewOrderSeq): ?>
                            <?php // The provider's own order number — a printed string such as `31#1`,
                            ?>
                            <?php // not a count and not anything this application derives.
                            ?>
                            <th>Seq</th>
                        <?php endif; ?>
                        <?php
                        // One column answering one question: can this order's Mix recording be played
                        // right now? Not "Recording / Conversation" any more — the conversation is a
                        // different thing, reached from the order number, and naming it here made the
                        // column look like two.
?>
                        <th>Recording</th>
                        <?php if ($canVerifyOrders): ?>
                            <?php // Beside Status, because the two answer the same kind of question
    ?>
                            <?php // about an order: what the machine made of it, and what a person
    ?>
                            <?php // made of it. The actions that change an order stay further right.
    ?>
                            <th>Verification</th>
                        <?php endif; ?>
                        <th>Practice Order</th>
                        <?php // Immediately after Demo URL, because it is the result of pressing it:
?>
                        <?php // the orders a trainee produced from this call, each compared on its own.
?>
                        <th>Demo Orders</th>
                        <?php if ($practiceMode): ?>
                            <?php // The topic this source order is practised under. One per order.
    ?>
                            <?php // "Set Topic" where it can be set; just "Topic" where it is read.
    ?>
                            <th><?= $canAssignTopics ? 'Set Topic' : 'Topic' ?></th>
                        <?php endif; ?>
                        <?php if ($canViewOrderReceipts): ?>
                            <?php // The real order's receipt, as the provider printed it. Offered only
    ?>
                            <?php // where the order is actually mirrored here, so a link can never
    ?>
                            <?php // name an order this store does not have.
    ?>
                            <th>View Receipt</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group): ?>
                        <?php $status = $group->aggregateStatus(); ?>
                        <?php
// Whether this row has a source order that actually exists, decided once and used
// by the checkbox, the Set Topic button and the Demo URL cell alike.
//
// The demo-link reader has already answered it for the whole page: it looks the
// order up by `(account_id, source_order_id)` and reports `OrderNotFound` when
// there is no such row. Every other status means the order WAS found and something
// else is missing, so reusing it costs no query and cannot disagree with the
// "Order not found" the next cell prints.
$rowOrderId = $group->orderId ?? '';
                        $rowLinkStatus = $rowOrderId !== '' && isset($demoLinks[$rowOrderId])
                            ? $demoLinks[$rowOrderId]->status
                            : DemoLinkStatus::NoOrderId;
                        $rowHasSourceOrder = $rowOrderId !== ''
                            && $rowLinkStatus !== DemoLinkStatus::NoOrderId
                            && $rowLinkStatus !== DemoLinkStatus::OrderNotFound;

                        // Resolved once for the row, because two cells need it: Seq prints what it holds,
                        // and View Receipt exists only when there is one. Looked up here rather than in
                        // the first of those cells so the second does not depend on the first having been
                        // rendered.
                        $mirrored = $rowOrderId === '' ? null : ($mirroredOrders[$rowOrderId] ?? null);
                        ?>
                        <?php
                        // Read by the review dialog's Original transcript button, which is opened from this
                        // row and has no other way to know which order it is looking at. A deliberately
                        // inert attribute name: `data-a2t-transcripts` is what the page-wide click handler
                        // binds to, and putting that on the `<tr>` would make a click anywhere in the row
                        // open the transcript dialog. Absent when there is nothing transcribed, which is
                        // what keeps the button hidden rather than opening an empty dialog.
                        ?>
                        <tr<?= $group->arriving !== null
                                ? ' data-a2t-arriving-call="' . Html::encode($group->arriving->callSessionId) . '"'
                                : '' ?><?= $group->hasAnyTranscript()
                                            ? ' data-a2t-row-transcripts="' . Html::encode(
                                                $groupUrl($routes['transcripts'], $group->key),
                                            ) . '"'
                                            : '' ?>>
                            <?php if ($canAssignTopics): ?>
                                <?php
                                // Only a row whose source order really exists can carry a topic: the
                                // assignment keys on `(store, order)` and the service refuses a pair that
                                // is not this store's, so a checkbox here would offer a selection the
                                // server would reject — and reject the whole batch with it, since a bulk
                                // assignment is all-or-nothing. Such a row keeps its place in the table and
                                // simply offers no checkbox.
                                ?>
                                <td class="practice-select">
                                    <?php if ($rowHasSourceOrder): ?>
                                        <input type="checkbox" form="practice-bulk-form"
                                            name="source_order_ids[]"
                                            value="<?= Html::encode($rowOrderId) ?>"
                                            data-practice-select
                                            aria-label="Select order <?= Html::encode($rowOrderId) ?>">
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td data-ot-label="Order">
                                <?php if ($group->orderId !== null && $group->hasReviewableRecording()): ?>
                                    <?php // One way in to every recording of this call. The channels it
                                    ?>
                                    <?php // offers are the Details buttons already in this row, so the
                                    ?>
                                    <?php // page carries nothing extra for it and nothing is fetched
                                    ?>
                                    <?php // until it is opened.
                                    ?>
                                    <button class="a2t-order-id a2t-order-id--open" type="button"
                                        title="<?= Html::encode($orderWhen($group)) ?>"
                                        data-a2t-order-open="<?= Html::encode($group->orderId) ?>">#<?= Html::encode($group->orderId) ?></button>
                                <?php elseif ($group->orderId !== null): ?>
                                    <?php // Nothing in this row has finished, so there is nothing for a
                                    ?>
                                    <?php // details view to show. Manage Audio is where it is watched.
                                    ?>
                                    <span class="a2t-order-id"
                                        title="<?= Html::encode($orderWhen($group)) ?>">#<?= Html::encode($group->orderId) ?></span>
                                <?php else: ?>
                                    <?php
                                    // An upload that named no order is still its own row — never merged
                                    // with every other order-less upload. It says so rather than showing
                                    // a blank cell that would read as missing data.
                                    ?>
                                    <span class="util-muted">No order</span>
                                <?php endif; ?>
                                <?php
                                /*
                             * The timestamps are gone from the cell, not from the data.
                             *
                             * Three lines under every order number — Call time, and Added or
                             * Downloaded — tripled the height of the busiest column in the table to
                             * carry facts nobody scans a list for. `callTimeRaw` and
                             * `latestActivityAt` are still read, still ordered by, and still shown
                             * where they are the subject rather than the footnote: the Call
                             * Recordings page and the conversation dialog.
                             *
                             * They survive here as a title, so the one reader in twenty who wants
                             * the date can hover for it without the other nineteen paying a row's
                             * height for it.
                             */
                        ?>
                            </td>
                            <?php if ($canViewOrderSeq): ?>
                                <?php
                        // The provider's own number for this order, printed exactly as mirrored.
                        // An order this store does not have, and one that has no seq, both read
                        // as a dash — the receipt cell beside it is where that difference is
                        // actually actionable, so it is not spelled out twice.
                                ?>
                                <td data-ot-label="Seq" class="a2t-cell-tight">
                                    <?php if ($mirrored !== null && $mirrored->seq !== null): ?>
                                        <span class="ot-seq"><?= Html::encode($mirrored->seq) ?></span>
                                    <?php else: ?>
                                        <span class="util-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td data-ot-label="Recording">
                                <?= $conversationCell($group) ?>
                            </td>
                            <?php
                            // No Status cell. What it reported was an aggregate over all three
                            // channels, so one failed secondary download made a row with perfectly
                            // good Mix audio look broken. The Recording cell to the left now answers
                            // the only question this table needs answered, about the one recording it
                            // offers. `aggregateStatus()` is untouched and still computed — nothing
                            // about transcription, ingestion or state transitions changed.
                        ?>
                            <?php if ($canVerifyOrders): ?>
                                <td data-ot-label="Verification" class="ot-verify-cell">
                                    <?php
                                $verification = $rowOrderId === ''
                                    ? null
                                    : ($orderVerifications[$rowOrderId] ?? OrderVerificationStatus::none());
                                ?>
                                    <?php if (!$rowHasSourceOrder || $verification === null): ?>
                                        <?php
                                    // Nothing to verify. The same rule the Set Topic cell applies, from
                                    // the same answer: a recording whose order was never mirrored is not
                                    // an order anybody can make a statement about.
                                        ?>
                                        <span class="util-muted">&mdash;</span>
                                    <?php else: ?>
                                        <?php
                                        // A real form, posting to a real endpoint. The script turns the
                                        // click into a confirmation and sends it by fetch; with no script
                                        // it submits directly and the server answers with a redirect. The
                                        // control is the same element either way, so there is nothing the
                                        // script has to hide once it loads — and therefore nothing that can
                                        // be seen for a moment before it does.
                                        ?>
                                        <form class="ot-verify" method="post"
                                            data-ot-verify
                                            data-ot-order="<?= Html::encode($rowOrderId) ?>"
                                            data-ot-verified="<?= $verification->isVerified ? '1' : '0' ?>"
                                            action="<?= Html::encode($urlGenerator->generate(
                                                $routes['verification'],
                                                [
                                                    'sourceId' => $store->sourceId,
                                                    'sourceOrderId' => $rowOrderId,
                                                ],
                                            )) ?>">
                                            <?= $csrfField ?>
                                            <?php
                                            // The state this control was rendered from. The endpoint
                                            // refuses a write whose version has moved on, so a tab left
                                            // open across somebody else's change cannot silently undo it.
                                        ?>
                                            <input type="hidden" name="version"
                                                value="<?= $verification->version ?>" data-ot-version>
                                            <?php
                                        // `decision`, not `action`: a control named `action` becomes a
                                        // property of its own form element and shadows `form.action`,
                                        // so the script would read an <input> where the URL should be
                                        // and post to it. It did, once.
                                        ?>
                                            <input type="hidden" name="decision" data-ot-decision
                                                value="<?= $verification->isVerified ? 'unverified' : 'verified' ?>">
                                            <?php if ($verification->isVerified): ?>
                                                <button class="badge badge--success ot-verify__badge"
                                                    type="submit" data-ot-verify-button
                                                    title="<?= Html::encode(
                                                        $verification->verifiedLine ?? 'Verified.',
                                                    ) ?>">
                                                    <span aria-hidden="true">&check;</span> Verified
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn--sm ot-verify__button"
                                                    type="submit" data-ot-verify-button>Verify</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td data-ot-label="Demo URL" class="a2t-cell-tight">
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
                                            data-ot-demo-url>Practice Order</button>
                                    </form>
                                    <?php // Filled by the script on a refusal — most often "somebody else is testing this".
                                    ?>
                                    <span class="ot-demo-message" role="alert" data-ot-demo-message hidden></span>
                                <?php else: ?>
                                    <?php // Plain text, not a dead link: nothing to click is clearer than something that cannot work.
                                    ?>
                                    <span class="util-muted"><?= Html::encode($demo->status->message()) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-ot-label="Demo orders" class="a2t-cell-tight">
                                <?php
                                // Counted for the whole page in one query before rendering; this cell prints the
                                // answer and links on to Order Testing's own page for that order. A dash is the honest
                                // answer for "nothing has been created from this call yet" — a "View (0)" would be a
                                // link to an empty page.
                                $demoCount = $demoOrderCounts[$orderKey] ?? 0;
                        ?>
                                <?php if ($orderKey !== '' && $demoCount > 0): ?>
                                    <?php
                            // One link, two parts: the word that says what pressing it does, and a
                            // count that says how much is behind it. The count is inside the
                            // anchor rather than beside it, so there is one target to click and
                            // one thing for a keyboard to land on — a badge that was its own
                            // control would be a second way to the same page.
                            //
                            // The visible text is "View 4"; the accessible name says what the 4
                            // counts, which the eye gets from the column heading and a screen
                            // reader does not.
                                    ?>
                                    <a class="ot-demo-orders" aria-label="View <?= $demoCount ?> demo order<?=
                                                                                                            $demoCount === 1 ? '' : 's' ?> for this order"
                                        href="<?= Html::encode($urlGenerator->generate(
                                            $routes['demoOrders'],
                                            ['sourceId' => $store->sourceId, 'sourceOrderId' => $orderKey],
                                        )) ?>"><span class="ot-demo-orders__label">View</span><span
                                            class="ot-demo-orders__count" aria-hidden="true"><?=
                                                                                        Html::encode((string) $demoCount) ?></span></a>
                                <?php else: ?>
                                    <span class="util-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($practiceMode): ?>
                                <?php $assigned = $orderKey === '' ? null : ($topicAssignments[$orderKey] ?? null); ?>
                                <td data-ot-label="Topic" class="practice-topic-cell">
                                    <?php
                                    // One row for every shape this cell takes: the topic's name, a badge
                                    // when it is no longer offered, and the control that changes it, all on
                                    // one line. It wraps rather than overflows, so a long topic name pushes
                                    // the control down instead of widening the table.
                                ?>
                                    <div class="practice-topic-cell__stack">
                                        <?php if (!$rowHasSourceOrder): ?>
                                            <?php
                                        // No source order behind this recording, so there is nothing to
                                        // practise. Said plainly and with the reason, rather than offering
                                        // a button the server would refuse. The recording itself is still
                                        // fully reachable from the cells to the left.
                                            ?>
                                            <?php
                                            // A dash, and nothing after it.
                                            //
                                            // The reason used to be spelled out beside it —
                                            // "No matching source order" — a sentence in a
                                            // column of chips, on every row with no order
                                            // behind it. The same fact is already two columns
                                            // to the left, where Practice Order says "Order not
                                            // found" and is the cell a reader acts on.
                                            //
                                            // The RULE is unchanged: such a row still carries
                                            // no checkbox, still offers no editor, and a topic
                                            // posted for it is still refused by the service.
                                            ?>
                                            <span class="util-muted">&mdash;</span>
                                        <?php else: ?>
                                            <?php if (!$canAssignTopics): ?>
                                                <?php
                                                // Read-only surfaces say what the topic is, and nothing more.
                                                //
                                                // It used to be a button that opened the topic's details. The
                                                // details are a topic's own information, not this order's, and
                                                // a name that looks like a link in a table of orders reads as
                                                // "this will take me somewhere about the order". Topic
                                                // Management still has the dialog, where a topic is the subject
                                                // rather than a column.
                                                ?>
                                                <div class="practice-topic-cell__name">
                                                    <?php
                                                    /*
                                         * A chip, not a line of text.
                                         *
                                         * One topic per order, so the cell is a single token rather
                                         * than a sentence — and a token is what the eye picks out of
                                         * a column while scanning. `title` carries the full name so
                                         * a long one can be truncated without being lost.
                                         *
                                         * "No topic" is drawn as a chip too, in the muted variant:
                                         * an empty cell reads as missing data, and a chip reads as
                                         * an answer.
                                         */
                                                ?>
                                                    <?php if ($assigned === null): ?>
                                                        <span class="practice-chip practice-chip--none">No topic</span>
                                                    <?php else: ?>
                                                        <span class="practice-chip practice-topic__value"
                                                            title="<?= Html::encode($assigned->name) ?>"><?=
                                                                                                        Html::encode($assigned->name) ?></span>
                                                        <?php if (!$assigned->isAssignable()): ?>
                                                            <?php
                                                        // Which of the two states it is, in the width a table
                                                        // column actually has. The assignment itself stays:
                                                        // this is history, not a mistake to clean up.
                                                            ?>
                                                            <span class="badge badge--muted"><?=
                                                                                                Html::encode($assigned->statusLabel()) ?></span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php
                                            // Where topics are assigned, the control IS the answer: the select
                                            // below already shows the current topic as its selected option, and
                                            // printing the same name beside it was the same fact twice in a
                                            // column that has room for one.
                        ?>
                                            <?php if ($canAssignTopics): ?>
                                                <?php
                            // One control, inline, instead of a button that opens a picker.
                            //
                            // Its own form per row, posting to the same endpoint the bulk
                            // toolbar uses. The script turns a change into a confirmation
                            // and submits only when the reader confirms; with no script the
                            // Save below submits directly, so the page still works and the
                            // server is the only thing that ever decides.
                            //
                            // The currently assigned topic is an option even when it is no
                            // longer offered — otherwise initialising the control would
                            // silently propose unassigning a historical topic, and the
                            // first stray click would carry it out.
                            $currentValue = $assigned === null ? null : (string) $assigned->id;
                                                $currentOffered = $assigned !== null && $assigned->isAssignable();
                                                ?>
                                                <form class="practice-assign" method="post"
                                                    data-practice-assign-row
                                                    data-practice-order="<?= Html::encode($orderKey) ?>"
                                                    data-practice-current-label="<?= Html::encode($assigned?->name ?? '') ?>"
                                                    action="<?= Html::encode($urlGenerator->generate(
                                                        $routes['topicAssign'],
                                                        ['sourceId' => $store->sourceId],
                                                    )) ?>">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="source_order_id"
                                                        value="<?= Html::encode($orderKey) ?>">
                                                    <?php foreach ($topicChoices as $choice): ?>
                                                        <input type="hidden" name="filter_topics[]"
                                                            value="<?= Html::encode($choice) ?>">
                                                    <?php endforeach; ?>

                                                    <?php
                                                    // What the control looks like is decided by what is
                                                    // STORED, not by what is selected. The class is rendered
                                                    // by the server from the saved assignment, so choosing a
                                                    // different topic changes the selected option and nothing
                                                    // else: the row keeps saying what it currently is until a
                                                    // confirmed save reloads the page. Cancel therefore has
                                                    // nothing to undo, and a failed save cannot leave the row
                                                    // looking like something it is not.
                                                    $assignedClass = $assigned === null
                                                        ? ''
                                                        : ($assigned->isAssignable()
                                                    ? ' practice-assign__select--assigned'
                                                    : ' practice-assign__select--historical');
                                                ?>
                                                    <div class="ot-topic">
                                                        <?php
                                                    /*
                                                 * What the order carries, as a chip.
                                                 *
                                                 * The control used to BE the answer — a select
                                                 * showing the current topic as its selected option —
                                                 * which meant the column's resting state was a
                                                 * dropdown on every row. A chip states the fact and
                                                 * the gear beside it offers to change it, so a table
                                                 * being read looks like a table and only a row being
                                                 * edited looks like a form.
                                                 *
                                                 * `title` carries the full name, so a long one can be
                                                 * truncated in the chip without being lost.
                                                 */
                                                ?>
                                                        <?php if ($assigned === null): ?>
                                                            <span class="practice-chip practice-chip--none">No topic</span>
                                                        <?php else: ?>
                                                            <span class="practice-chip<?= $currentOffered
                                                                                    ? ''
                                                                                    : ' practice-chip--historical' ?>"
                                                                title="<?= Html::encode(
                                                                    $assigned->name
                                                                        . ($currentOffered ? '' : ' (' . $assigned->statusLabel() . ')'),
                                                                ) ?>"><?= Html::encode($assigned->name) ?></span>
                                                        <?php endif; ?>
                                                        <?php
                                                        /*
                                                 * The editor: a `<details>`, like the topic filter
                                                 * above the table.
                                                 *
                                                 * The browser opens and closes it, gives the summary
                                                 * a tab stop and announces its state, with no script
                                                 * at all — which is what this page's `script-src
                                                 * 'self'` and its no-JavaScript path both need. The
                                                 * script adds only click-outside and Escape, and it
                                                 * is the same handler the filter picker already uses.
                                                 *
                                                 * **Radios, and a Save.** Choosing does not submit:
                                                 * opening the editor and changing your mind must cost
                                                 * nothing, so nothing is written until Save is
                                                 * pressed. That is also the confirmation — the select
                                                 * this replaces needed a dialog to ask "did you mean
                                                 * to?" precisely because choosing WAS saving.
                                                 */
                        ?>
                                                        <details class="ot-topic__picker" data-practice-topic-picker>
                                                            <summary class="ot-topic__gear"
                                                                aria-label="<?= Html::encode(
                                                                    'Change the topic for order ' . $orderKey,
                                                                ) ?>"><?= AudioToTextIcons::svg(AudioToTextIcons::PENCIL, 'Change topic') ?></summary>
                                                            <div class="ot-topic__panel">
                                                                <p class="ot-topic__title">Set topic</p>
                                                                <?php if ($assignableTopics === []): ?>
                                                                    <p class="ot-topic__empty">
                                                                        No active topics yet — create one in Topic Management.
                                                                    </p>
                                                                <?php else: ?>
                                                                    <ul class="ot-topic__options">
                                                                        <?php
                                                                        // "No topic" first, and it is the
                                                                        // UNASSIGN path: the endpoint reads an
                                                                        // empty `topic_id` as "take it off",
                                                                        // which is the same contract the
                                                                        // select's first option had.
                                                                    ?>
                                                                        <li>
                                                                            <label class="ot-topic__option">
                                                                                <input type="radio" name="topic_id" value=""
                                                                                    <?= $currentValue === null ? ' checked' : '' ?>>
                                                                                <span class="util-muted">No topic</span>
                                                                            </label>
                                                                        </li>
                                                                        <?php if ($assigned !== null && !$currentOffered): ?>
                                                                            <?php
                                                                        // The topic this order actually
                                                                        // carries, when it is no longer
                                                                        // offered. Listed so that opening
                                                                        // the editor and pressing Save
                                                                        // cannot silently unassign it.
                                                                            ?>
                                                                            <li>
                                                                                <label class="ot-topic__option">
                                                                                    <input type="radio" name="topic_id"
                                                                                        value="<?= $assigned->id ?>" checked>
                                                                                    <span><?= Html::encode($assigned->name) ?>
                                                                                        <span class="util-muted">(<?=
                                                                                                                    Html::encode($assigned->statusLabel()) ?>)</span></span>
                                                                                </label>
                                                                            </li>
                                                                        <?php endif; ?>
                                                                        <?php foreach ($assignableTopics as $topic): ?>
                                                                            <li>
                                                                                <label class="ot-topic__option">
                                                                                    <input type="radio" name="topic_id"
                                                                                        value="<?= $topic->id ?>"
                                                                                        <?= $currentValue === (string) $topic->id
                                                                                            ? ' checked'
                                                                                            : '' ?>>
                                                                                    <span><?= Html::encode($topic->name) ?></span>
                                                                                </label>
                                                                            </li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                    <button class="btn btn--primary btn--sm ot-topic__save"
                                                                        type="submit">Save</button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </details>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <?php if ($canViewOrderReceipts): ?>
                                <td data-ot-label="View receipt" class="a2t-cell-tight">
                                    <?php if ($mirrored !== null): ?>
                                        <?php
                                        /*
                                     * A plain link, and a real URL.
                                     *
                                     * Nothing is fetched while this table loads: the provider is
                                     * called when, and only when, this is clicked. `target="_blank"`
                                     * leaves Manage Orders where it is, and `rel` is what stops the
                                     * opened tab reaching back through `window.opener` — it is an
                                     * image from somebody else's server, so it gets no handle on
                                     * this page.
                                     */
                                        $receiptUrl = $urlGenerator->generate($routes['orderReceipt'], [
                                            'sourceId' => $store->sourceId,
                                            'sourceOrderId' => $group->orderId,
                                        ]);
                                        ?>
                                        <a class="ot-rowaction ot-receipt" href="<?= Html::encode($receiptUrl) ?>"
                                            target="_blank" rel="noopener noreferrer"
                                            aria-label="View the Order58 receipt for order <?= Html::encode((string) $group->orderId) ?> (opens in a new tab)">Receipt</a>
                                    <?php else: ?>
                                        <?php
                                        // No mirrored order, so there is no verified id to ask about and
                                        // no link is built. Saying which of the two is missing would be
                                        // guessing: the order may be new, or the number may be a typo.
                                        ?>
                                        <span class="util-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pageCount > 1): ?>
            <?php
            // Three, not four: this template sits at `src/AudioToText/Web/OrderTesting`, one level
            // shallower than the audio pages at `.../Web/Job/Store` that this was copied from. At
            // four it resolved above `src/` and the page died — but only for a store with more
            // than one page of orders, which is why it stood for so long.
            ?>
            <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
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

<?php // ---- The dialogs -------------------------------------------------------------------------
?>
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
        aria-labelledby="a2t-upload-title" <?= $uploadOpen ? ' open' : '' ?>>
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
                            <path d="M12 16V3m-4 4 4-4 4 4M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4" />
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

                <?php if (!$canChooseRecordingType): ?>
                    <?php
    /*
     * Mix / Common, stated rather than asked.
     *
     * This surface shows one recording per order, so there is one answer and the
     * operator should not have to give it. The field is still posted because the
     * endpoint is shared with the Audio-to-Text page, and the server pins the value
     * regardless of what arrives — see `allowsRecordingTypeChoice()`.
     */
                    ?>
                    <input type="hidden" name="recording_type"
                        value="<?= Html::encode(RecordingType::Mixed->value) ?>">
                <?php else: ?>
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
                <?php endif; ?>

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
            <?php
// Both of these are ways to change the recording: the action strip the script fills with
// Replace and Generate, and the way through to the correction page. A reader who may do
// neither gets neither element — so there is nothing for the script to fill, and nothing
// to click.
?>
            <?php if ($canVerifyOrders): ?>
                <?php
    // The same decision the column offers, in the place a reader reaches it: they have just
    // listened to the call and read the conversation, which is when somebody actually
    // decides an order has been checked.
    //
    // It owns no state. The click is handed to that row's own form in the table, so the two
    // controls cannot disagree and there is no second record of anything. Hidden until the
    // dialog is opened on an ORDER — opened on a single recording there is nothing to make
    // a statement about, and the script leaves it as it found it. Hidden in the markup
    // rather than revealed-then-hidden, so it cannot appear for a moment on the way in.
                ?>
                <button class="btn btn--sm" type="button" data-ot-verify-dialog hidden></button>
            <?php endif; ?>
            <?php if ($capabilities->canReplaceAudio || $capabilities->canGenerateTts): ?>
                <div class="a2t-dialog__acts" data-a2t-review-actions></div>
            <?php endif; ?>
            <?php if ($capabilities->canOpenFullEditor): ?>
                <a class="btn btn--sm" data-a2t-full-editor href="#">Open full editor</a>
            <?php endif; ?>
            <?php
            // The machine's own output, beside the corrected conversation it produced.
            //
            // The listing's Actions column used to hold the only way in, and the two realms that share
            // this page do not share a fallback: an administrator could still reach it from the audio
            // store page, while an operation user is deliberately kept out of that module altogether.
            // Hiding the column would therefore have taken the original transcript away from one role
            // and not the other.
            //
            // So the way in moved here rather than disappearing. It opens the SAME dialog the column
            // opened, over the SAME order-testing-scoped route — the script fills in which order from
            // the row the dialog was opened on, and a row with nothing transcribed leaves this hidden.
            // No new endpoint, no second copy of the transcript, and no change to who may read one.
?>
            <?php if ($canReadConversation): ?>
                <button class="btn btn--sm" type="button" data-a2t-review-transcripts hidden>
                    Original transcript
                </button>
            <?php endif; ?>
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
    <?php
// No token, no corrections. The script reads this element to send `X-CSRF-Token`; without it the
// editing paths have nothing to send, and the surfaces that omit it mount none of those endpoints.
?>
    <?php
// Also rendered where playback is recorded. The agent cannot edit a transcript and still has no
// address that would — the endpoints are not mounted for that realm — but reporting what they
// played is a POST, and a POST here carries this token or it is refused.
?>
    <?php if ($capabilities->canEditTranscript || $capabilities->canTrackPlayback): ?>
        <div class="a2t-review-token" data-a2t-review-token hidden><?= $csrfField ?></div>
    <?php endif; ?>
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
        <?php
/*
 * Where the conversation's turns are drawn — and only where they may be read.
 *
 * Omitted rather than hidden. A `display: none` container is still a container: the script
 * would fill it, the words would be in the document, and anyone could read them from the
 * element inspector. With the element absent the script skips the whole thread-building
 * branch (it checks for this node), so on an audio-only surface no turn is ever built.
 *
 * The payload the dialog fetches still carries the turns, because it is the same response
 * that carries the audio URLs this dialog needs. Splitting that would mean a second endpoint
 * for one surface; it is recorded as a known limit rather than worked around badly.
 */
?>
        <?php if ($canReadConversation): ?>
            <div class="a2t-chat__scroll a2t-dialog-scroll" data-a2t-review-scroll></div>
        <?php endif; ?>
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
<?php if ($capabilities->canTranscribe): ?>
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

<?php endif; ?>

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
<?php if ($capabilities->canReplaceAudio): ?>
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
            <?php // Named by the server when the dialog opens. Never a control the reader can change.
            ?>
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
<?php endif; ?>

<?php if ($capabilities->canGenerateTts): ?>
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
<?php endif; ?>

<?php
// Manage Audio is the replacement surface and it carries the Generate form inside it, so it is gated
// on either of the two capabilities that reach it. A reader with neither gets no dialog at all —
// which is also why the button that opens it is not emitted for them.
?>
<?php if ($capabilities->canReplaceAudio || $capabilities->canGenerateTts): ?>
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
<?php endif; ?>

<?php if ($canViewAgentEvents): ?>
    <?php
    // Two levels, and the second is a sibling rather than a child: a `<dialog>` opened from inside
    // another one stacks in the top layer, and closing the inner one must not take the outer with it.
    // So both sit here at the end of the document, the summary opens the history, and closing the
    // history leaves the summary exactly as it was — which is the drill-down the reader expects.
    ?>
    <dialog class="source-modal ot-agent-dialog" id="ot-agent-summary-dialog" data-a2t-dialog
        aria-labelledby="ot-agent-summary-title">
        <div class="source-modal__head">
            <h2 class="source-modal__title" id="ot-agent-summary-title">Agent playback events</h2>
            <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="source-modal__body">
            <p class="source-modal__meta" data-ot-agent-scope></p>
            <?php
            // Said once, where the figures are, because a count of playback events is not a count of
            // people who listened and a reader should not have to infer the difference.
    ?>
            <p class="util-muted ot-agent-note">
                These are playback events this application recorded. They show what was played and how
                far it got, not that somebody was listening.
            </p>
            <div data-ot-agent-summary></div>
        </div>
    </dialog>

    <dialog class="source-modal ot-agent-dialog" id="ot-agent-history-dialog" data-a2t-dialog
        aria-labelledby="ot-agent-history-title">
        <div class="source-modal__head">
            <h2 class="source-modal__title" id="ot-agent-history-title">Agent playback history</h2>
            <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="source-modal__body">
            <p class="source-modal__meta" data-ot-agent-history-scope></p>
            <div data-ot-agent-history></div>
        </div>
    </dialog>
<?php endif; ?>

<?php
// The topic-details dialog is NOT rendered here any more.
//
// Nothing on this page opens it: a topic's name is information in a column of orders, not a way in
// to the topic itself. Topic Management still renders the same shared partial, where a topic is the
// subject of the page rather than one of its facts — so the dialog, its script and its markup all
// still exist, and only the trigger that did not belong here is gone.
?>

<?php if ($canAssignTopics): ?>
    <?php
    // Two confirmations, and nothing else down here any more.
    //
    // The picker that used to live in a dialog is now the select on each row, so what is left is the
    // only part that was ever worth a modal: saying out loud what is about to change, before it
    // changes. Both are filled by the script from the control that opened them and submit the form
    // that control already belongs to — there is no second endpoint and no second copy of the rules.
    ?>
    <dialog class="source-modal practice-dialog" id="practice-confirm-dialog" data-practice-dialog
        aria-labelledby="practice-confirm-title">
        <div class="source-modal__head">
            <h2 class="source-modal__title" id="practice-confirm-title">Confirm topic assignment</h2>
            <button class="source-modal__close" type="button" data-practice-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="source-modal__body">
            <dl class="practice-details">
                <dt>Order</dt>
                <dd class="util-mono" data-practice-confirm-order></dd>

                <dt>Current topic</dt>
                <dd data-practice-confirm-current></dd>

                <dt>New topic</dt>
                <dd data-practice-confirm-next></dd>
            </dl>
            <p class="field__hint" data-practice-confirm-note></p>
        </div>
        <div class="a2t-confirm__actions">
            <?php
            // Cancel restores the select to what is actually stored. Nothing has been sent at this
            // point, so there is nothing to undo — but a control left showing a choice the database
            // does not hold is a page that lies about its own state.
    ?>
            <button class="btn btn--sm" type="button" data-practice-dialog-close>Cancel</button>
            <button class="btn btn--primary btn--sm" type="button"
                data-practice-confirm-submit>Confirm assignment</button>
        </div>
    </dialog>

    <?php
    // One dialog for both directions. Verify and Unverify ask different questions and carry different
    // weight, but they are the same decision about the same order, and two dialogs would be two places
    // to keep the order number, the wording and the button labels in step. The script fills all three.
    ?>
    <dialog class="source-modal practice-dialog" id="ot-verify-dialog" data-practice-dialog
        aria-labelledby="ot-verify-title">
        <div class="source-modal__head">
            <h2 class="source-modal__title" id="ot-verify-title" data-ot-verify-title>Verify Order</h2>
            <button class="source-modal__close" type="button" data-practice-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="source-modal__body">
            <dl class="practice-details">
                <dt>Order</dt>
                <dd class="util-mono" data-ot-verify-order></dd>
            </dl>
            <p data-ot-verify-message></p>
        </div>
        <div class="a2t-confirm__actions">
            <button class="btn btn--sm" type="button" data-practice-dialog-close>Cancel</button>
            <button class="btn btn--primary btn--sm" type="button"
                data-ot-verify-confirm>Confirm Verification</button>
        </div>
    </dialog>

    <dialog class="source-modal practice-dialog" id="practice-bulk-confirm-dialog" data-practice-dialog
        aria-labelledby="practice-bulk-confirm-title">
        <div class="source-modal__head">
            <h2 class="source-modal__title" id="practice-bulk-confirm-title">Confirm bulk assignment</h2>
            <button class="source-modal__close" type="button" data-practice-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="source-modal__body">
            <dl class="practice-details">
                <dt>Orders</dt>
                <dd data-practice-bulk-confirm-count></dd>

                <dt>New topic</dt>
                <dd data-practice-bulk-confirm-topic></dd>
            </dl>
            <p class="field__hint">
                Applies to the orders selected on this page. Any topic those orders already carry is
                replaced.
            </p>
        </div>
        <div class="a2t-confirm__actions">
            <button class="btn btn--sm" type="button" data-practice-dialog-close>Cancel</button>
            <button class="btn btn--primary btn--sm" type="button"
                data-practice-bulk-confirm-submit>Confirm assignment</button>
        </div>
    </dialog>
<?php endif; ?>