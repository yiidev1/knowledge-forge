<?php

declare(strict_types=1);

use App\Order58\Web\TestRecordingChannels\ChannelDiagnosis;
use App\Order58\Web\TestRecordingChannels\ChannelProbeResult;
use App\Order58\Web\TestRecordingChannels\FixtureAvailability;
use App\Order58\Web\TestRecordingChannels\RecordingChannel;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Separated-channel recording probe.
 *
 * Decides nothing: the diagnosis, the WAV verdict and whether anything is playable are all settled
 * before they arrive here. Nothing binary is ever written into this page — only a size, a type and a
 * verdict — and no credential exists to leak, because none is sent.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var string $recordingId
 * @var string $merchantId
 * @var string $time
 * @var string $company
 * @var string $name
 * @var RecordingChannel $channel
 * @var list<RecordingChannel> $channels
 * @var string|null $fileName null when the recording id was refused, so no name was built
 * @var bool $useFixtures
 * @var bool $fixturesPermitted
 * @var string $sourceLabel
 * @var string $candidateDescription
 * @var array{validationError: ?string, result: ?ChannelProbeResult, diagnosis: ?ChannelDiagnosis, url: ?string, failure: ?string}|null $outcome
 */

$this->setTitle('Recording channel test');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Recording channel test'],
]);

$result = $outcome['result'] ?? null;
$diagnosis = $outcome['diagnosis'] ?? null;

$linkParams = [
    'recording_id' => $recordingId,
    'merchant_id' => $merchantId,
    'time' => $time,
    'company' => $company,
    'name' => $name,
    'channel' => $channel->value,
];

if ($useFixtures) {
    $linkParams[FixtureAvailability::QUERY_PARAMETER] = FixtureAvailability::FIXTURE;
}

$downloadBase = $urlGenerator->generate('order58.test-recording-channels.download');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Recording channel test</h1>
        <p class="page-header__subtitle">
            Probes the client's separated-channel recordings &mdash; mixed, caller and callee. Diagnostic
            only: nothing is saved, queued or written to the database.
        </p>
    </div>
    <a class="btn" href="<?= Html::encode($urlGenerator->generate('order58.test-recording-apis')) ?>">
        Original recording API test
    </a>
</div>

<?php
// The single most important thing on this page. Stated before any result, because the dangerous failure
// is not a 404 — it is a request that answers 200 with the MIXED file while the page calls it Caller.
?>
<div class="alert alert--warning" role="status">
    <p>
        <strong>Caller and callee retrieval is not production-ready.</strong>
        The client has supplied a filename convention but not a working example URL, so how the external
        API expects a separated channel to be requested is unconfirmed.
    </p>
    <p>
        Mixed uses the request the existing tool already makes successfully. Caller and callee currently
        use: <em><?= Html::encode($candidateDescription) ?></em>
    </p>
    <p>
        A 404 for a channel may mean the file does not exist &mdash; or that this reading of the request
        format is wrong. Do not treat either as confirmation until the client supplies a real URL.
    </p>
</div>

<div class="card">
    <h2 class="card__title">Request</h2>

    <form method="get" action="<?= Html::encode($urlGenerator->generate('order58.test-recording-channels')) ?>">
        <input type="hidden" name="submitted" value="1">

        <div class="field">
            <label class="field__label" for="recording_id">Recording ID</label>
            <input class="field__control" type="text" inputmode="numeric" id="recording_id" name="recording_id"
                   value="<?= Html::encode($recordingId) ?>">
            <div class="field__hint">Digits only. Anything else is refused before a request is built.</div>
        </div>

        <div class="field">
            <label class="field__label" for="merchant_id">Merchant ID</label>
            <input class="field__control" type="text" inputmode="numeric" id="merchant_id" name="merchant_id"
                   value="<?= Html::encode($merchantId) ?>">
            <?php
            // Said plainly rather than left to look like an oversight: the confirmed endpoint has no
            // merchant parameter, so sending one would be inventing something the provider never asked for.
?>
            <div class="field__hint">
                Recorded for your reference. The confirmed recording endpoint takes no merchant parameter,
                so this is <strong>not</strong> sent to the API.
            </div>
        </div>

        <div class="field">
            <span class="field__label">Channel</span>
            <?php foreach ($channels as $option): ?>
                <label class="a2t-checkbox" for="channel-<?= Html::encode($option->value) ?>">
                    <input type="radio" id="channel-<?= Html::encode($option->value) ?>" name="channel"
                           value="<?= Html::encode($option->value) ?>"
                        <?= $option === $channel ? 'checked' : '' ?>>
                    <span>
                        <?= Html::encode($option->label()) ?>
                        <?php
            // The concrete name only for an id that would be accepted. For a refused id the
            // pattern is shown instead, rather than a name this tool would never build.
                ?>
                        &mdash; <code><?= $fileName === null
                    ? Html::encode($option->fileNameFor('{id}'))
                    : Html::encode($option->fileNameFor($recordingId)) ?></code>
                        <?php if (!$option->liveRetrievalIsConfirmed()): ?>
                            <em>(request format unconfirmed)</em>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="field">
            <label class="field__label" for="time">Time</label>
            <input class="field__control" type="text" id="time" name="time" value="<?= Html::encode($time) ?>">
            <div class="field__hint">YYYY-MM-DD.</div>
        </div>

        <div class="field">
            <label class="field__label" for="company">Company</label>
            <input class="field__control" type="text" id="company" name="company" value="<?= Html::encode($company) ?>">
        </div>

        <div class="field">
            <label class="field__label" for="name">Name</label>
            <input class="field__control" type="text" id="name" name="name" value="<?= Html::encode($name) ?>">
            <div class="field__hint">
                Passed through for mixed. For caller/callee it may be replaced by the channel filename,
                depending on which reading is configured above.
            </div>
        </div>

        <?php if ($fixturesPermitted): ?>
            <div class="field">
                <label class="a2t-checkbox" for="use_fixtures">
                    <input type="checkbox" id="use_fixtures" name="source" value="<?= Html::encode(FixtureAvailability::FIXTURE) ?>"
                        <?= $useFixtures ? 'checked' : '' ?>>
                    <span>Answer from local fixture files instead of the external API</span>
                </label>
                <div class="field__hint">
                    Development and test only, and never on by default &mdash; it must be ticked on every
                    request. Fixtures exist because this machine is not on the API's IP allowlist, so a live
                    request fails before any of this tool's own logic runs.
                </div>
            </div>
        <?php endif; ?>

        <button class="btn btn--primary" type="submit">Test / Fetch Recording</button>
    </form>
</div>

<?php if ($outcome !== null): ?>
    <div class="card">
        <h2 class="card__title">Result</h2>

        <dl class="a2t-meta">
            <div><dt>Recording ID</dt><dd class="util-mono"><?= Html::encode($recordingId) ?></dd></div>
            <div><dt>Merchant ID</dt><dd class="util-mono"><?= Html::encode($merchantId) ?></dd></div>
            <div><dt>Channel</dt><dd><?= Html::encode($channel->label()) ?></dd></div>
            <div><dt>Generated filename</dt><dd class="util-mono"><?= $fileName === null ? '&mdash;' : Html::encode($fileName) ?></dd></div>
            <div><dt>Source</dt><dd><?= Html::encode($sourceLabel) ?></dd></div>
        </dl>

        <?php if (($outcome['validationError'] ?? null) !== null): ?>
            <div class="alert alert--error" role="alert">
                <p><strong>Nothing was sent.</strong> <?= Html::encode($outcome['validationError']) ?></p>
            </div>
        <?php else: ?>
            <?php if (($outcome['url'] ?? null) !== null): ?>
                <h3 class="field__label">Request</h3>
                <pre class="source-view"><?= Html::encode($outcome['url']) ?></pre>
            <?php endif; ?>

            <?php if ($diagnosis !== null): ?>
                <div class="alert alert--<?= $diagnosis->isSuccess() ? 'success' : ($diagnosis->isFailure() ? 'error' : 'warning') ?>"
                     role="status">
                    <p><strong><?= Html::encode($diagnosis->headline) ?></strong></p>
                    <p><?= Html::encode($diagnosis->advice) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($result !== null): ?>
                <dl class="a2t-meta">
                    <div><dt>HTTP status</dt><dd class="util-mono"><?= $result->status ?> <?= Html::encode($result->reason) ?></dd></div>
                    <div><dt>Content-Type</dt><dd class="util-mono"><?= $result->contentType === '' ? '(not sent)' : Html::encode($result->contentType) ?></dd></div>
                    <div><dt>Bytes received</dt><dd><?= Html::encode($result->sizeLabel()) ?> (<?= $result->bytes ?>)</dd></div>
                    <div><dt>Content-Length</dt><dd class="util-mono"><?= $result->contentLength === null ? '(not sent)' : Html::encode($result->contentLength) ?></dd></div>
                    <div>
                        <dt>Valid WAV</dt>
                        <dd><?= $result->wav->isWav ? 'Yes' : 'No' ?> &mdash; <?= Html::encode($result->wav->summary) ?></dd>
                    </div>
                </dl>

                <?php
                // Only ever a non-audio body: an error page or a JSON refusal. Printing the first kilobyte
                // of a real WAV would fill the page with mojibake and tell nobody anything.
                $preview = $result->textPreview();
                ?>
                <?php if ($preview !== null): ?>
                    <h3 class="field__label">Response body</h3>
                    <pre class="source-view"><?= Html::encode($preview) ?></pre>
                <?php endif; ?>

                <?php if ($result->isPlayable()): ?>
                    <?php
                    $playUrl = $downloadBase . '?' . http_build_query($linkParams + ['disposition' => 'inline']);
                    $downloadUrl = $downloadBase . '?' . http_build_query($linkParams);
                    ?>
                    <h3 class="field__label">Play</h3>
                    <div class="a2t-player">
                        <?php
                        // Same-origin, so neither the external URL nor any credential reaches the browser.
                        // The endpoint re-validates every value and re-checks the WAV header before it
                        // hands over a byte.
                    ?>
                        <audio class="a2t-player__control" controls preload="none"
                               src="<?= Html::encode($playUrl) ?>"></audio>
                    </div>

                    <p>
                        <a class="btn btn--primary" href="<?= Html::encode($downloadUrl) ?>">Download</a>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (($outcome['failure'] ?? null) !== null): ?>
                <h3 class="field__label">Details</h3>
                <pre class="source-view"><?= Html::encode($outcome['failure']) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
