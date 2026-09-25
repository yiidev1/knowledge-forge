<?php

declare(strict_types=1);

use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\ChannelProbeResult;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Integration\Order58Recording\LatestCallsResult;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Web\TestRecordingChannels\RecordingChannelsAsset;
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
 * @var Yiisoft\Assets\AssetManager $assetManager
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
 * @var string $accountId
 * @var string $limit
 * @var int $maxLimit
 * @var array{validationError: ?string, result: ?LatestCallsResult, failure: ?string}|null $latest
 * @var string $candidateDescription
 * @var string|null $timeError the Time field's own verdict after a submit, null when it was fine
 * @var string $timeErrorMessage the one wording for a bad Time, handed to the client-side validator
 * @var array{validationError: ?string, result: ?ChannelProbeResult, diagnosis: ?ChannelDiagnosis, url: ?string, failure: ?string}|null $outcome
 */

// Field-level validation for the Time input, and nothing else. The page works exactly as before
// without it: the server refuses a bad date either way, which is what this must never be mistaken for.
$assetManager->register(RecordingChannelsAsset::class);

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

$pageUrl = $urlGenerator->generate('order58.test-recording-channels');
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
// Stated before any result. The request format is settled now, so what an operator needs up front is the
// remaining reason a well-formed channel request still fails: the merchant is not on the client's list.
?>
<div class="alert alert--info" role="status">
    <p>
        <strong>Separated channels exist only for the merchants on the client's list.</strong>
        Every account has a mixed recording. Caller and callee files are generated only for listed
        merchants, so a session id belonging to any other merchant returns nothing for those two however
        well-formed the request is.
    </p>
    <p>
        A 404 on caller or callee therefore usually means <em>this merchant has no separated
        channels</em>, not that the URL is wrong. Confirm the merchant is on the list before treating it
        as a fault.
    </p>
    <p>
        Request format: <em><?= Html::encode($candidateDescription) ?></em>
    </p>
</div>

<?php // ---- Step 1: find a call ------------------------------------------------------------------?>
<div class="card">
    <h2 class="card__title">Latest calls</h2>
    <pre class="source-view">GET https://order58.xrainbow.com/api/external/recording/{accountId}/latest-calls?limit={limit}</pre>
    <p class="field__hint">
        Optional. Look up an account's recent calls and pick one, instead of typing a session id by hand.
        This calls the list endpoint only &mdash; it never fetches a recording.
    </p>

    <form method="get" action="<?= Html::encode($pageUrl) ?>">
        <input type="hidden" name="load_calls" value="1">
        <?php
        // The channel form's values ride along, so loading a list does not discard a half-filled form
        // below. Nothing here submits that form: only `submitted=1` does, and this carries `load_calls=1`.
?>
        <input type="hidden" name="recording_id" value="<?= Html::encode($recordingId) ?>">
        <input type="hidden" name="merchant_id" value="<?= Html::encode($merchantId) ?>">
        <input type="hidden" name="channel" value="<?= Html::encode($channel->value) ?>">
        <input type="hidden" name="time" value="<?= Html::encode($time) ?>">
        <input type="hidden" name="company" value="<?= Html::encode($company) ?>">
        <input type="hidden" name="name" value="<?= Html::encode($name) ?>">
        <?php if ($useFixtures): ?>
            <input type="hidden" name="<?= Html::encode(FixtureAvailability::QUERY_PARAMETER) ?>"
                   value="<?= Html::encode(FixtureAvailability::FIXTURE) ?>">
        <?php endif; ?>

        <div class="field">
            <label class="field__label" for="account_id">Account ID</label>
            <input class="field__control" type="text" inputmode="numeric" id="account_id" name="account_id"
                   value="<?= Html::encode($accountId) ?>">
            <?php
    // Not merged with Merchant ID, and the reason is on the page rather than only in a docblock:
    // the evidence for them being the same concept is mixed, and a diagnostic tool that guessed
    // would be reporting an assumption as a fact.
?>
            <div class="field__hint">
                The account whose calls to list. <strong>Kept separate from Merchant ID below</strong>
                &mdash; the two have not been confirmed to be the same identifier.
            </div>
        </div>

        <div class="field">
            <label class="field__label" for="limit">Limit</label>
            <input class="field__control" type="text" inputmode="numeric" id="limit" name="limit"
                   value="<?= Html::encode($limit) ?>">
            <div class="field__hint">1 to <?= $maxLimit ?>.</div>
        </div>

        <button class="btn" type="submit">Load Latest Calls</button>
    </form>

    <?php if ($latest !== null): ?>
        <?php if (($latest['validationError'] ?? null) !== null): ?>
            <div class="alert alert--error" role="alert">
                <p><strong>Nothing was sent.</strong> <?= Html::encode($latest['validationError']) ?></p>
            </div>
        <?php else: ?>
            <?php $calls = $latest['result'] ?? null; ?>

            <?php if ($calls !== null): ?>
                <h3 class="field__label">Request</h3>
                <pre class="source-view"><?= Html::encode($calls->url) ?></pre>

                <div class="alert alert--<?= $calls->diagnosis->isSuccess() ? 'success' : 'error' ?>" role="status">
                    <p><strong><?= Html::encode($calls->diagnosis->headline) ?></strong></p>
                    <p><?= Html::encode($calls->diagnosis->advice) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($calls !== null && $calls->hasCalls()): ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Call Session ID</th>
                                <th>Call Time</th>
                                <th>Order ID</th>
                                <th>&nbsp;</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calls->calls as $call): ?>
                                <tr>
                                    <td><code><?= Html::encode($call->callSessionId === '' ? '—' : $call->callSessionId) ?></code></td>
                                    <td><?= Html::encode($call->shortCallTime()) ?></td>
                                    <td><?= Html::encode($call->orderId === '' ? '—' : $call->orderId) ?></td>
                                    <td class="table__actions">
                                        <?php if ($call->isUsable() && $call->hasValidRecordingId()): ?>
                                            <?php
                                // Pre-fills the channel form below and nothing else: it carries
                                // no `submitted`, so no recording is fetched until that form is
                                // submitted. The date is only carried when it could be read with
                                // certainty — see CallSummary::derivedDate().
                                $derived = $call->derivedDate();
                                            $useParams = [
                                                'recording_id' => $call->callSessionId,
                                                'merchant_id' => $merchantId,
                                                'channel' => $channel->value,
                                                'time' => $derived ?? $time,
                                                'company' => $company,
                                                'name' => $name,
                                                'account_id' => $accountId,
                                                'limit' => $limit,
                                                'load_calls' => '1',
                                            ];

                                            if ($useFixtures) {
                                                $useParams[FixtureAvailability::QUERY_PARAMETER] = FixtureAvailability::FIXTURE;
                                            }
                                            ?>
                                            <a class="btn btn--secondary btn--sm"
                                               href="<?= Html::encode($pageUrl . '?' . http_build_query($useParams)) ?>#channel-test">
                                                Use This Call
                                            </a>
                                            <?php if ($derived === null && $call->callTime !== ''): ?>
                                                <div class="field__hint">Date not readable &mdash; Time left unchanged.</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="util-muted">No usable session id</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($calls->unusableCount() > 0): ?>
                    <p class="util-muted">
                        <?= $calls->unusableCount() ?> row(s) had no session id this page could use, and
                        are shown above without a button rather than hidden.
                    </p>
                <?php endif; ?>

                <p class="util-muted">
                    Read from the response for convenience only &mdash; nothing here is saved. Selecting a
                    call fills the form below; it does not fetch a recording.
                </p>
            <?php endif; ?>

            <?php $preview = $calls?->bodyPreview(); ?>
            <?php if ($preview !== null && ($calls === null || !$calls->hasCalls())): ?>
                <h3 class="field__label">Response body</h3>
                <pre class="source-view"><?= Html::encode($preview) ?></pre>
            <?php endif; ?>

            <?php if (($latest['failure'] ?? null) !== null): ?>
                <div class="alert alert--error" role="alert">
                    <p><strong>The request failed before any HTTP response arrived.</strong></p>
                </div>
                <pre class="source-view"><?= Html::encode($latest['failure']) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php // ---- Step 2: test a channel ----------------------------------------------------------------?>
<div class="card" id="channel-test">
    <h2 class="card__title">Request</h2>

    <form method="get" action="<?= Html::encode($pageUrl) ?>">
        <input type="hidden" name="submitted" value="1">
        <?php
        // Carried through so a fetch does not wipe the call list above.
?>
        <input type="hidden" name="account_id" value="<?= Html::encode($accountId) ?>">
        <input type="hidden" name="limit" value="<?= Html::encode($limit) ?>">
        <?php if ($latest !== null): ?>
            <input type="hidden" name="load_calls" value="1">
        <?php endif; ?>

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
                        <?php
                // The request segment, not the filename: it is what actually goes in the URL, and
                // showing the `.wav` name here is what led to it being sent as `name`.
                //
                // Without the `/fetch/` prefix on purpose. For a refused id nothing resembling a URL may
                // appear anywhere on this page — that absence is how a test proves no request was built
                // from a traversal attempt, and a prefix printed here would defeat it.
                ?>
                        &mdash; <code><?= $fileName === null
                    ? Html::encode($option->requestSegmentFor('{id}'))
                    : Html::encode($option->requestSegmentFor($recordingId)) ?></code>
                        <?php if ($option->separatedChannelsNeedAListedMerchant()): ?>
                            <em>(listed merchants only)</em>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="field">
            <label class="field__label" for="time">Time</label>
            <?php
            // Deliberately still type="text". A native date picker would render the value in the
            // browser's locale, submit an empty string for a half-typed date rather than the text the
            // operator typed, and change how this form looks for a rule the server states as
            // YYYY-MM-DD. What was missing was the message, not the input type.
            //
            // `data-time-input` is the hook the validator attaches to, and `data-time-message` carries
            // the server's own wording so the two can never diverge. With JavaScript off none of this
            // does anything and the server refuses a bad date exactly as before.
?>
            <input class="field__control<?= $timeError === null ? '' : ' field__control--error' ?>"
                   type="text" id="time" name="time" value="<?= Html::encode($time) ?>"
                   inputmode="numeric" autocomplete="off" spellcheck="false" placeholder="YYYY-MM-DD"
                   data-time-input
                   data-time-message="<?= Html::encode($timeErrorMessage) ?>"
                   aria-describedby="time-hint"
                   aria-errormessage="time-error"
                <?= $timeError === null ? '' : 'aria-invalid="true"' ?>>
            <?php
// Always rendered, hidden until there is something to say: the validator fills and unhides
// this element rather than creating one, so the message occupies the same place whether it
// came from the server or from the keystroke before last.
?>
            <div class="field__error" id="time-error"<?= $timeError === null ? ' hidden' : '' ?>><?=
    $timeError === null ? '' : Html::encode($timeError)
?></div>
            <div class="field__hint" id="time-hint">YYYY-MM-DD. A real calendar date &mdash; 2026-02-31 is refused.</div>
        </div>

        <div class="field">
            <label class="field__label" for="company">Company</label>
            <input class="field__control" type="text" id="company" name="company" value="<?= Html::encode($company) ?>">
        </div>

        <div class="field">
            <label class="field__label" for="name">Name</label>
            <input class="field__control" type="text" id="name" name="name" value="<?= Html::encode($name) ?>">
            <div class="field__hint">
                The download display name, not part of addressing the file. Passed through for mixed.
                For caller and callee it is set to the session id plus the channel &mdash; with no
                extension, because the provider adds one and <code>.wav.wav</code> is the result if it is
                included here.
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
            <?php
    // What was actually sent, not a `.wav` filename. The old "Generated filename" row showed
    // `22433929.wav` beside every result, including a caller request that transmits no filename at
    // all — which invited exactly the question of why the URL did not contain it.
    ?>
            <div>
                <dt>Path segment</dt>
                <dd class="util-mono"><?= $fileName === null
                    ? '&mdash;'
                    : Html::encode($channel->requestSegmentFor($recordingId)) ?></dd>
            </div>
            <div>
                <dt>Name sent</dt>
                <dd class="util-mono"><?= $fileName === null
                    ? '&mdash;'
                    : Html::encode($channel === RecordingChannel::Mixed
                        ? $name
                        : $channel->downloadNameFor($recordingId)) ?></dd>
            </div>
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
