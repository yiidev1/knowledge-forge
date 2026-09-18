<?php

declare(strict_types=1);

use App\AudioToText\Domain\Speaker\SpeakerMarkers;
use App\AudioToText\Domain\Tts\AiAudioState;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Conversion\AiAudio\AiAudioPage;
use App\AudioToText\Web\Conversion\AiAudio\AiAudioRow;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * Clean AI training audio for one call.
 *
 * Decides nothing. State, staleness, what may be generated and why it may not are all settled by
 * {@see AiAudioPage} before they arrive here — a template working out whether audio was stale would be
 * a second implementation of the rule that decides whether to spend money.
 *
 * ## One column, three steps, in the order they happen
 *
 * A summary, then the recording somebody uploaded, the text it was turned into, and the audio read back
 * from that text. That ordering *is* the explanation: a reader who has never seen this feature can
 * follow what produced what without being told. Each step is one card, and a separate Customer/Agent
 * pair puts both sides inside the step they belong to rather than repeating the step per side — three
 * cards for a call, however many recordings it has.
 *
 * ## The transcript opens in a modal, and the modal needs no JavaScript
 *
 * A transcript is thousands of characters; printed inline it buries the three steps it is meant to
 * illustrate. It opens through `:target` — an anchor and a CSS rule — rather than a scripted dialog,
 * because the policy here is `script-src 'self'` with no inline JavaScript, and every dialog handler in
 * `admin.js` is bound to one specific feature. A `:target` modal needs no script at all and degrades to
 * an ordinary in-page anchor if the stylesheet fails to load.
 *
 * The rest needs no script either: `<audio controls>` is native and both buttons are ordinary
 * CSRF-protected forms.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var AiAudioPage $page
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('AI training audio');

$conversation = $page->conversation;
$store = $page->store;

$this->setParameter('breadcrumbs', array_values(array_filter([
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    $store === null ? null : [
        'label' => $store->name,
        'route' => AudioToTextRoute::STORE,
        'arguments' => ['sourceId' => $store->sourceId],
    ],
    ['label' => 'AI audio'],
])));

$localTime = static fn(?DateTimeImmutable $at): string => $at === null
    ? '—'
    : $appTimeZone->format($at, 'M j, Y g:i A');

$duration = static fn(?float $seconds): string => $seconds === null
    ? '—'
    : number_format($seconds, 1) . 's';

$fileSize = static function (?int $bytes): string {
    if ($bytes === null || $bytes <= 0) {
        return '—';
    }

    return $bytes < 1048576
        ? number_format($bytes / 1024, 0) . ' KB'
        : number_format($bytes / 1048576, 1) . ' MB';
};

// Resolved once: the three steps describe the same recordings, so they read from one list rather than
// each re-deriving it. The conversation's own status is the weaker of a pair's two, so the flow strip
// cannot claim "Completed" while half the call is still processing.
$children = $conversation->children;
$transcriptionStatus = $conversation->status();

$generatedCount = 0;
foreach ($page->rows as $countRow) {
    if ($countRow->isPlayable()) {
        $generatedCount++;
    }
}

// Whether the uploaded recording is still on disk is a property of the job row, and step 1 walks the
// conversation's *children*, which carry no path. Indexed by public id rather than by position: a mixed
// call has one of each, a Customer/Agent pair has two, and nothing guarantees the two lists are ordered
// alike. A child with no entry simply gets no player, which is the same honest answer as before.
$retainedOriginals = [];
foreach ($page->rows as $countRow) {
    if ($countRow->job->hasRetainedRecording()) {
        $retainedOriginals[$countRow->job->publicId] = true;
    }
}
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">AI training audio</h1>
        <p class="page-header__subtitle">
            A clean synthetic reading of this call's transcript. It does not replace the original audio.
        </p>
    </div>
    <a class="btn" href="<?= Html::encode($urlGenerator->generate(
        AudioToTextRoute::CONVERSION,
        ['publicId' => $conversation->publicId],
    )) ?>">Back to conversion</a>
</div>

<?php if (!$page->providerConfigured): ?>
    <div class="alert alert--warning" role="status">
        <p>AI audio is not configured on this server yet, so nothing can be generated.</p>
        <?php
        // The variable names only. An administrator is the person who would fix these, and naming the
        // setting is what makes that possible — but no value is printed, here or anywhere.
    ?>
        <?php foreach ($page->providerProblems as $problem): ?>
            <p class="util-mono"><?= Html::encode($problem) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php // ---- Summary --------------------------------------------------------------------------------?>
<div class="card">
    <h2 class="card__title">Audio Conversion Details</h2>

    <dl class="a2t-summary">
        <div><dt>Store</dt><dd><?= Html::encode($store->name ?? 'No store') ?></dd></div>
        <div><dt>Recording type</dt><dd><?= Html::encode($conversation->mode->label()) ?></dd></div>
        <div><dt>Duration</dt><dd><?= Html::encode($duration($conversation->totalDurationSeconds())) ?></dd></div>
        <div><dt>Uploaded by</dt><dd><?= Html::encode($conversation->uploadedByUsername ?? '—') ?></dd></div>
        <div><dt>Uploaded</dt><dd><?= Html::encode($localTime($conversation->createdAt)) ?></dd></div>
        <div><dt>Requested at upload</dt><dd><?= $conversation->generateAiAudio ? 'Yes' : 'No' ?></dd></div>
    </dl>

    <?php
    // The whole feature in one strip. Three steps, each carrying its own state, so the stage this call
    // has reached is readable at a glance without opening anything below. It carries no heading of its
    // own: three numbered boxes reading 1-2-3 do not need to be told they are a sequence.
?>
    <ol class="a2t-flow">
        <li class="a2t-flow__step a2t-flow__step--done">
            <span class="a2t-flow__n">1</span>
            <span class="a2t-flow__label">Original audio</span>
            <span class="a2t-flow__state">Uploaded</span>
        </li>
        <li class="a2t-flow__step a2t-flow__step--<?=
        $transcriptionStatus->badgeModifier() === 'completed' ? 'done' : 'pending' ?>">
            <span class="a2t-flow__n">2</span>
            <span class="a2t-flow__label">Text transcript</span>
            <span class="a2t-flow__state"><?= Html::encode($transcriptionStatus->label()) ?></span>
        </li>
        <li class="a2t-flow__step a2t-flow__step--<?= $generatedCount > 0 ? 'done' : 'pending' ?>">
            <span class="a2t-flow__n">3</span>
            <span class="a2t-flow__label">AI generated audio</span>
            <span class="a2t-flow__state"><?= $generatedCount > 0 ? 'Ready' : 'Not generated' ?></span>
        </li>
    </ol>

    <p class="a2t-note">
        Read from this call's transcript exactly as it stands, including every saved correction.
        Nothing is summarised, reworded or re-priced.
    </p>
</div>

<?php // ---- Step 1: the original ---------------------------------------------------------------?>
<div class="card">
    <h2 class="card__title"><span class="a2t-step">Step 1</span> Original audio</h2>

    <?php foreach ($children as $child): ?>
        <div class="a2t-block">
            <div class="a2t-block__head">
                <span class="a2t-block__name" title="<?= Html::encode($child->originalFilename) ?>">
                    <?= Html::encode($child->originalFilename) ?>
                </span>
                <?php // JobStatus has no badge helper; the rest of the module lowercases the case name.?>
                <span class="a2t-badge a2t-badge--<?= Html::encode(strtolower($child->status->value)) ?>">
                    <?= Html::encode($child->status->label()) ?>
                </span>
            </div>

            <dl class="a2t-summary">
                <div><dt>File name</dt><dd><?= Html::encode($child->originalFilename) ?></dd></div>
                <div><dt>Type</dt><dd><?= Html::encode($conversation->mode->label()) ?></dd></div>
                <div><dt>Duration</dt><dd><?= Html::encode($duration($child->durationSeconds)) ?></dd></div>
                <div><dt>Uploaded date</dt><dd><?= Html::encode($localTime($conversation->createdAt)) ?></dd></div>
            </dl>

            <?php if (isset($retainedOriginals[$child->publicId])): ?>
                <?php
                $originalUrl = $urlGenerator->generate(
                    AudioToTextRoute::JOB_ORIGINAL_FILE,
                    ['publicId' => $child->publicId],
                );
                ?>
                <div class="a2t-player a2t-player--compact">
                    <?php
                    // `preload="none"` because a page with several of these would otherwise fetch many
                    // megabytes nobody asked to hear. The endpoint serves byte ranges, so the scrubber
                    // works from the first seek rather than after a whole download.
                ?>
                    <audio class="a2t-player__control" controls preload="none"
                           src="<?= Html::encode($originalUrl) ?>"></audio>
                    <a class="btn btn--sm" href="<?= Html::encode($originalUrl . '?download=1') ?>">Download</a>
                </div>
            <?php else: ?>
                <?php
                // No player rather than a control that cannot work: the recording was not retained, or
                // has been collected under the retention window, so there are no bytes to serve.
                ?>
                <p class="a2t-note">
                    This recording is no longer stored on the server, so it cannot be played or
                    downloaded. The AI reading of it is in step 3.
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php // ---- Step 2: the transcript -------------------------------------------------------------?>
<div class="card">
    <h2 class="card__title"><span class="a2t-step">Step 2</span> Text transcript</h2>

    <?php foreach ($page->rows as $row): ?>
        <?php
        /** @var AiAudioRow $row */
        $job = $row->job;
        $source = $page->transcripts[$job->id] ?? null;

        // The effective text, chosen the way the generator chooses it: the reviewed layer once a
        // correction exists, the machine's own output otherwise. Read straight off the job, so this
        // template introduces no dependency of its own and cannot show text the audio was not read from.
        $reviewed = $job->isReviewed();
        $customerText = (string) ($reviewed ? $job->reviewedCustomerText : $job->customerText);
        $agentText = (string) ($reviewed ? $job->reviewedAgentText : $job->agentText);
        $wholeText = (string) $job->transcript;
        $hasText = $wholeText !== '' || $customerText !== '' || $agentText !== '';
        $modalId = 'a2t-transcript-' . $job->id;

        // Unpacked once: the row and the modal print the same four facts, and reading them through
        // `$source?->` at each use site narrows nothing for the next one.
        $sourceTitle = $source?->label() ?? 'Transcript';
        $revision = $source?->revision ?? 0;
        $editedAt = $source?->lastEditedAt;
        $editedBy = $source?->lastEditedBy;
        ?>
        <div class="a2t-block">
            <div class="a2t-block__head">
                <span class="a2t-block__name">
                    <?= Html::encode($sourceTitle) ?>
                    <?php if ($row->sourceLabel() !== null): ?>
                        &mdash; <?= Html::encode($row->sourceLabel()) ?>
                    <?php endif; ?>
                </span>
                <span class="a2t-badge a2t-badge--<?= Html::encode(strtolower($job->status->value)) ?>">
                    <?= Html::encode($job->status->label()) ?>
                </span>
            </div>

            <dl class="a2t-summary">
                <div><dt>Provider</dt><dd><?= Html::encode($job->transcriptionProvider()->label()) ?></dd></div>
                <div><dt>Source</dt><dd><?= Html::encode($sourceTitle) ?></dd></div>
                <div><dt>Revision</dt><dd><?= $revision ?></dd></div>
                <div>
                    <dt>Last edited</dt>
                    <dd>
                        <?php if ($editedAt === null): ?>
                            &mdash;
                        <?php else: ?>
                            <?= Html::encode($localTime($editedAt)) ?>
                            <?= $editedBy === null ? '' : 'by ' . Html::encode($editedBy) ?>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>

            <?php if ($hasText): ?>
                <p class="a2t-actions">
                    <a class="btn btn--primary btn--sm" href="#<?= Html::encode($modalId) ?>">View transcript</a>
                </p>
            <?php else: ?>
                <p class="a2t-note">No transcript text has been produced for this recording yet.</p>
            <?php endif; ?>
        </div>

        <?php if ($hasText): ?>
            <?php
            // Hidden until the URL fragment matches it, closed by a link back to `#`. The backdrop is
            // itself that link, so a click anywhere outside the panel closes it.
            ?>
            <div class="a2t-modal" id="<?= Html::encode($modalId) ?>" role="dialog" aria-modal="true"
                 aria-label="Transcript">
                <a class="a2t-modal__backdrop" href="#" aria-label="Close transcript"></a>
                <div class="a2t-modal__panel">
                    <div class="a2t-modal__head">
                        <div>
                            <h3 class="a2t-modal__title">
                                <?= Html::encode($sourceTitle) ?>
                                <?php if ($row->sourceLabel() !== null): ?>
                                    &mdash; <?= Html::encode($row->sourceLabel()) ?>
                                <?php endif; ?>
                            </h3>
                            <p class="a2t-modal__sub">
                                Revision <?= $revision ?>
                                <?php if ($editedAt !== null): ?>
                                    &middot; last edited <?= Html::encode($localTime($editedAt)) ?>
                                    <?= $editedBy === null ? '' : 'by ' . Html::encode($editedBy) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <a class="btn btn--sm a2t-modal__close" href="#">Close</a>
                    </div>

                    <div class="a2t-modal__body">
                        <?php
                        // Speaker-separated where the two sides exist, the whole transcript otherwise.
                        // `>>` markers are stripped on the way out: they are stored deliberately and are
                        // display noise, which is the one thing SpeakerMarkers exists to decide.
            ?>
                        <?php if ($customerText !== '' || $agentText !== ''): ?>
                            <?php foreach (['Customer' => $customerText, 'Agent' => $agentText] as $who => $text): ?>
                                <?php if ($text !== ''): ?>
                                    <h4 class="a2t-modal__speaker"><?= Html::encode($who) ?></h4>
                                    <p class="a2t-transcript"><?= Html::encode(SpeakerMarkers::strip($text)) ?></p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="a2t-transcript"><?= Html::encode(SpeakerMarkers::strip($wholeText)) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if ($page->needsSpeakerConfirmation()): ?>
    <?php
    // Names the fact, names the remedy, links to it — rather than an unexplained disabled button.
    ?>
    <div class="alert alert--warning" role="status">
        <p>
            Agent and Customer have not been confirmed for this call, and a synthetic voice would assert
            a speaker this page does not.
        </p>
        <?php
        $blocked = null;
    foreach ($page->rows as $candidate) {
        if ($candidate->state === AiAudioState::Blocked) {
            $blocked = $candidate;

            break;
        }
    }
    ?>
        <?php if ($blocked !== null && $blocked->job->status->value === 'COMPLETED'): ?>
            <p>
                <a href="<?= Html::encode($urlGenerator->generate(
                    AudioToTextRoute::JOB_REVIEW,
                    ['publicId' => $blocked->job->publicId],
                )) ?>">Confirm the speakers</a>,
                and the audio you asked for at upload will be generated automatically.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php // ---- Step 3: the generated audio --------------------------------------------------------?>
<div class="card">
    <h2 class="card__title"><span class="a2t-step">Step 3</span> AI generated audio</h2>

    <?php foreach ($page->rows as $row): ?>
        <?php
        /** @var AiAudioRow $row */
        $rendition = $row->rendition;
        $fileUrl = $urlGenerator->generate(
            AudioToTextRoute::JOB_AI_AUDIO_FILE,
            ['publicId' => $row->job->publicId],
        );
        ?>
        <div class="a2t-block">
            <div class="a2t-block__head">
                <span class="a2t-block__name"><?= Html::encode($row->title()) ?></span>
                <span class="a2t-badge a2t-badge--<?= Html::encode($row->state->badgeModifier()) ?>">
                    <?= Html::encode($row->state->label()) ?>
                </span>
            </div>

            <?php if ($row->isPlayable() && $rendition !== null): ?>
                <?php
                $voices = array_values(array_filter([
                    $rendition->modelCustomer,
                    $rendition->modelAgent,
                ]));
                ?>
                <dl class="a2t-summary">
                    <?php
                    // The column stores the provider as a constant (`DEEPGRAM`). Cased for reading here
                    // rather than in the domain, where the stored value is the value and shouting is the
                    // point — this is the only place it is shown to a person.
                ?>
                    <div><dt>Provider</dt><dd><?= Html::encode(ucfirst(strtolower($rendition->provider))) ?></dd></div>
                    <div>
                        <dt>Voice<?= count($voices) > 1 ? 's' : '' ?></dt>
                        <dd><?= $voices === [] ? '—' : Html::encode(implode(', ', $voices)) ?></dd>
                    </div>
                    <div><dt>Characters</dt><dd><?= number_format($rendition->characterCount ?? 0) ?></dd></div>
                    <div><dt>Size</dt><dd><?= Html::encode($fileSize($rendition->fileBytes)) ?></dd></div>
                    <div><dt>Generated date</dt><dd><?= Html::encode($localTime($rendition->generatedAt)) ?></dd></div>
                    <div>
                        <dt>Requested by</dt>
                        <dd><?= Html::encode($rendition->requestedByUsername ?? 'Automatically') ?></dd>
                    </div>
                </dl>
            <?php endif; ?>

            <?php if ($row->state === AiAudioState::Stale): ?>
                <div class="alert alert--warning" role="status">
                    <p>
                        Transcript changed after this audio was generated. The recording below is the
                        previous version and still plays; it is not regenerated automatically, because
                        generating costs money.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($row->state === AiAudioState::Failed && $rendition !== null && $rendition->errorMessage !== null): ?>
                <div class="alert alert--error" role="alert">
                    <?php
                // Already redacted on the way into the column: a response body is written by a third
                // party, and this one is rendered on a page.
                ?>
                    <p>
                        <?= Html::encode($rendition->errorMessage) ?>
                        <?= $row->isPlayable() ? 'The previous recording is still available below.' : '' ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($row->state === AiAudioState::DifferentVoice): ?>
                <p class="a2t-note">
                    Generated with a different voice setting than the one configured now. The words are
                    unchanged.
                </p>
            <?php endif; ?>

            <?php if ($row->blockedReason !== null): ?>
                <p class="a2t-note"><?= Html::encode($row->blockedReason) ?></p>
            <?php endif; ?>

            <?php if ($row->isPlayable()): ?>
                <div class="a2t-player a2t-player--compact">
                    <?php
                // `preload="none"` because a page with two of these would otherwise fetch several
                // megabytes nobody asked to hear.
                ?>
                    <audio class="a2t-player__control" controls preload="none"
                           src="<?= Html::encode($fileUrl) ?>"></audio>
                    <a class="btn btn--sm" href="<?= Html::encode($fileUrl . '?download=1') ?>">Download</a>
                </div>
            <?php elseif ($row->state !== AiAudioState::Blocked && $row->state !== AiAudioState::Unavailable): ?>
                <p class="a2t-note">No audio has been generated for this yet.</p>
            <?php endif; ?>

            <?php if ($row->omittedTurns > 0): ?>
                <p class="a2t-note">
                    <?= $row->omittedTurns ?>
                    turn<?= $row->omittedTurns === 1 ? ' was' : 's were' ?> left out:
                    <?= $row->omittedTurns === 1 ? 'it was' : 'they were' ?> not attributed to the Agent or
                    the Customer, so no voice could speak
                    <?= $row->omittedTurns === 1 ? 'it' : 'them' ?> honestly. Reassign on the correction
                    screen if <?= $row->omittedTurns === 1 ? 'it belongs' : 'they belong' ?> in the call.
                </p>
            <?php endif; ?>

            <?php if ($row->canGenerate): ?>
                <form class="a2t-generate" method="post" action="<?= Html::encode($urlGenerator->generate(
                    AudioToTextRoute::JOB_AI_AUDIO_GENERATE,
                    ['publicId' => $row->job->publicId],
                )) ?>">
                    <?= $csrf->hiddenInput() ?>
                    <input type="hidden" name="output_type" value="<?= Html::encode($row->outputType->value) ?>">
                    <?php
                    // The digest this page was rendered from. If somebody corrects the transcript in
                    // another tab, the button that was pressed was labelled with the old text and is not
                    // the one to honour — the idiom `review_count` already applies to speaker corrections.
                ?>
                    <input type="hidden" name="expected_hash" value="<?= Html::encode($row->currentHash) ?>">

                    <button class="btn btn--primary" type="submit"><?= Html::encode($row->buttonLabel()) ?></button>
                    <span class="a2t-note">
                        <?= number_format($row->characterCount) ?> characters
                        <?php if ($row->turnCount > 1): ?>
                            across <?= $row->turnCount ?> turns
                        <?php endif; ?>
                        will be sent to the speech provider. This is a paid action.
                    </span>
                </form>
            <?php elseif ($row->state === AiAudioState::Ready): ?>
                <p class="a2t-note">Up to date with the current transcript.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
