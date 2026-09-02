<?php

declare(strict_types=1);

use App\Order58\Web\TestRecordingApis\ProbeResult;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var string $apiLatestCalls
 * @var string $apiFetchRecording
 * @var int $maxLimit
 * @var string $accountIdRaw
 * @var string $limitRaw
 * @var string $callSessionIdRaw
 * @var string $timeRaw
 * @var string $companyRaw
 * @var string $nameRaw
 * @var array{validationError: ?string, result: ?ProbeResult, pretty: ?string, rows: list<array{callTime: string, callSessionId: string, orderId: string}>, failure: ?string}|null $latest
 * @var array{validationError: ?string, result: ?ProbeResult, pretty: ?string, rows: list<array{callTime: string, callSessionId: string, orderId: string}>, failure: ?string}|null $fetch
 * @var bool $canDownload true only when a successful, binary recording came back
 */

$this->setTitle('Order58 recording API test');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Recording API test'],
]);

$base = $urlGenerator->generate('order58.test-recording-apis');

/**
 * The output block both APIs share: request URL, status, every response header, content type and size,
 * then the body — as text when that is safe, as a description when it is binary.
 *
 * @param array<string, mixed> $outcome one of the two outcome arrays documented above
 */
$renderOutcome = static function (array $outcome): void {
    /** @var string|null $validationError */
    $validationError = $outcome['validationError'];
    /** @var string|null $failure */
    $failure = $outcome['failure'];
    /** @var string|null $pretty */
    $pretty = $outcome['pretty'];
    /** @var ProbeResult|null $result */
    $result = $outcome['result'];

    if ($validationError !== null) {
        echo '<div class="alert alert--error"><span class="alert__icon" aria-hidden="true">!</span><span>'
            . Html::encode($validationError)
            . ' Nothing was sent to the API.</span></div>';

        return;
    }

    if ($failure !== null) { ?>
        <h3 class="field__label">Transport error &mdash; no HTTP response</h3>
        <p class="field__hint">
            The request never produced an HTTP response (DNS, TLS, connection or timeout failure).
            The full exception is printed here on purpose &mdash; this page only.
        </p>
        <pre class="source-view"><?= Html::encode($failure) ?></pre>
        <?php
        return;
    }

    if ($result === null) {
        return;
    }
    ?>

    <h3 class="field__label">Request URL</h3>
    <pre class="source-view"><?= Html::encode($result->url) ?></pre>

    <h3 class="field__label">
        HTTP Status: <?= $result->status ?>
        <span class="badge <?= $result->isSuccessful() ? 'badge--success' : 'badge--error' ?>">
            <span class="badge__dot" aria-hidden="true"></span><?= Html::encode($result->reason) ?>
        </span>
    </h3>

    <h3 class="field__label">Content-Type</h3>
    <p class="field__hint"><?= $result->contentType === '' ? '(not sent)' : Html::encode($result->contentType) ?></p>

    <?php if ($result->contentDisposition !== ''): ?>
        <h3 class="field__label">Content-Disposition</h3>
        <p class="field__hint"><?= Html::encode($result->contentDisposition) ?></p>
    <?php endif; ?>

    <h3 class="field__label">Bytes received</h3>
    <p class="field__hint">
        <?= number_format($result->bytes) ?>
        <?php if ($result->contentLength !== null): ?>
            (Content-Length header: <?= Html::encode($result->contentLength) ?>)
        <?php endif; ?>
    </p>

    <h3 class="field__label">Response headers</h3>
    <pre class="source-view"><?= Html::encode($result->headerLines()) ?></pre>

    <h3 class="field__label">Response body</h3>
    <?php if ($result->isBinary): ?>
        <?php // Binary is described, never printed: raw audio bytes must not reach the HTML.?>
        <div class="alert alert--<?= $result->isSuccessful() ? 'success' : 'error' ?>">
            <span class="alert__icon" aria-hidden="true"><?= $result->isSuccessful() ? '✓' : '!' ?></span>
            <span>
                Binary response of <?= number_format($result->bytes) ?> bytes
                (<?= Html::encode($result->contentType === '' ? 'unknown type' : $result->contentType) ?>)
                &mdash; not rendered.
                <?= $result->isSuccessful() ? 'Recording received successfully. Nothing was saved.' : '' ?>
            </span>
        </div>
    <?php elseif ($result->preview === ''): ?>
        <p class="field__hint">Empty body.</p>
    <?php else: ?>
        <pre class="source-view"><?= Html::encode($result->preview) ?></pre>
        <?php if ($result->truncated): ?>
            <p class="field__hint">
                Showing the first <?= number_format(strlen($result->preview)) ?> of
                <?= number_format($result->bytes) ?> bytes.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($pretty !== null): ?>
        <h3 class="field__label">Response body (pretty-printed JSON)</h3>
        <pre class="source-view"><?= Html::encode($pretty) ?></pre>
    <?php endif; ?>
    <?php
};
?>

<div class="card">
    <h2 class="card__title">Order58 recording API test</h2>
    <p class="field__hint">
        Read-only probe of the two external recording endpoints. No credential is sent, nothing is stored,
        enqueued or downloaded, and each form calls only its own API.
    </p>
</div>

<?php // ---- API 1 --------------------------------------------------------------------------------?>
<div class="card">
    <h2 class="card__title">Latest Calls API</h2>
    <pre class="source-view">GET https://order58.xrainbow.com/api/external/recording/{accountId}/latest-calls?limit={limit}</pre>

    <form method="get" action="<?= Html::encode($base) ?>">
        <input type="hidden" name="api" value="<?= Html::encode($apiLatestCalls) ?>">
        <div class="field">
            <label class="field__label" for="account_id">Account ID</label>
            <input class="field__control" type="text" inputmode="numeric" id="account_id" name="account_id"
                   value="<?= Html::encode($accountIdRaw) ?>">
        </div>
        <div class="field">
            <label class="field__label" for="limit">Limit</label>
            <input class="field__control" type="text" inputmode="numeric" id="limit" name="limit"
                   value="<?= Html::encode($limitRaw) ?>">
            <div class="field__hint">1 to <?= $maxLimit ?>.</div>
        </div>
        <button class="btn btn--primary" type="submit">Send Latest Calls Request</button>
    </form>

    <?php if ($latest !== null): ?>
        <?php $renderOutcome($latest); ?>

        <?php if ($latest['rows'] !== []): ?>
            <h3 class="field__label">Calls</h3>
            <p class="field__hint">
                Read from the response above for convenience only &mdash; nothing here is saved. Use the
                session id to pre-fill the Fetch Recording form below; it does not call that API.
            </p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Call Time</th>
                            <th>Session ID</th>
                            <th>Order ID</th>
                            <th>&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($latest['rows'] as $row): ?>
                            <tr>
                                <td><?= Html::encode($row['callTime']) ?></td>
                                <td><code><?= Html::encode($row['callSessionId']) ?></code></td>
                                <td><?= Html::encode($row['orderId']) ?></td>
                                <td class="table__actions">
                                    <?php if ($row['callSessionId'] !== ''): ?>
                                        <?php
                                        // Pre-fills API 2's form only: no `api` change, so the recording
                                        // endpoint is not called until that form is submitted.
                                        $prefill = $base . '?' . http_build_query([
                                            'api' => $apiLatestCalls,
                                            'account_id' => $accountIdRaw,
                                            'limit' => $limitRaw,
                                            'call_session_id' => $row['callSessionId'],
                                            'time' => $timeRaw,
                                            'company' => $companyRaw,
                                            'name' => $nameRaw,
                                        ]);
                                        ?>
                                        <a class="btn btn--secondary btn--sm" href="<?= Html::encode($prefill) ?>#fetch-recording">Use below</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php // ---- API 2 --------------------------------------------------------------------------------?>
<div class="card" id="fetch-recording">
    <h2 class="card__title">Fetch Recording API</h2>
    <pre class="source-view">GET https://order58.xrainbow.com/api/external/recording/fetch/{callSessionId}?time={time}&amp;company={company}&amp;name={name}</pre>

    <form method="get" action="<?= Html::encode($base) ?>">
        <input type="hidden" name="api" value="<?= Html::encode($apiFetchRecording) ?>">
        <div class="field">
            <label class="field__label" for="call_session_id">Call Session ID</label>
            <input class="field__control" type="text" inputmode="numeric" id="call_session_id" name="call_session_id"
                   value="<?= Html::encode($callSessionIdRaw) ?>">
            <div class="field__hint">Digits only.</div>
        </div>
        <div class="field">
            <label class="field__label" for="time">Time</label>
            <input class="field__control" type="text" id="time" name="time" value="<?= Html::encode($timeRaw) ?>">
            <div class="field__hint">YYYY-MM-DD.</div>
        </div>
        <div class="field">
            <label class="field__label" for="company">Company</label>
            <input class="field__control" type="text" id="company" name="company" value="<?= Html::encode($companyRaw) ?>">
        </div>
        <div class="field">
            <label class="field__label" for="name">Name</label>
            <input class="field__control" type="text" id="name" name="name" value="<?= Html::encode($nameRaw) ?>">
        </div>
        <button class="btn btn--primary" type="submit">Send Fetch Recording Request</button>
    </form>

    <?php if ($fetch !== null): ?>
        <?php $renderOutcome($fetch); ?>

        <?php if ($canDownload): ?>
            <?php
            // A separate GET route, so the diagnostic above never triggers a download on its own —
            // only following this link does. It carries the four values just tested, so nothing is
            // retyped, and the endpoint re-validates every one of them before calling out again.
            $downloadUrl = $urlGenerator->generate('order58.test-recording-apis.download') . '?' . http_build_query([
                'call_session_id' => $callSessionIdRaw,
                'time' => $timeRaw,
                'company' => $companyRaw,
                'name' => $nameRaw,
            ]);
            ?>
            <h3 class="field__label">Download</h3>
            <p class="field__hint">
                Re-runs the same request and streams the response to your browser. Nothing is written to
                disk or to the database on the way through.
            </p>
            <a class="btn btn--primary" href="<?= Html::encode($downloadUrl) ?>">Download recording</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
