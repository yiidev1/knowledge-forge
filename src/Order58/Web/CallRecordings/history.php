<?php

declare(strict_types=1);

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportHistoryPage;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var Csrf $csrf
 * @var CallImportHistoryPage $result
 * @var int $page
 * @var list<RecordingChannel> $channels
 * @var AppTimeZone $appTimeZone
 * @var string $pageUrl
 * @var string $recordingsUrl
 * @var string $retryUrl
 */

$this->setTitle('Download History');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Order58 Call Recordings', 'route' => 'order58.call-recordings'],
    ['label' => 'Download History'],
]);

$csrfField = (string) $csrf->hiddenInput();

/** A timestamp in the business timezone, or a dash where there is nothing to show yet. */
$when = static function (?DateTimeImmutable $at) use ($appTimeZone): string {
    return $at === null
        ? '<span class="util-muted">—</span>'
        : Html::encode($appTimeZone->format($at, 'M j, Y g:i A'));
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Download History</h1>
        <p class="page-header__subtitle">
            Call recordings downloaded for listening, newest first. Recordings asked for in order to be
            transcribed are not listed here — they have their own history on the Order58 calls page.
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn btn--secondary" href="<?= Html::encode($recordingsUrl) ?>">Back to Call Recordings</a>
    </div>
</div>

<section class="card">
    <?php if ($result->items === []): ?>
        <div class="empty">
            <div class="empty__title">Nothing downloaded yet</div>
            <p class="field__hint">
                Recordings downloaded from the Order58 Call Recordings page appear here.
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Order ID</th>
                        <th>Call session ID</th>
                        <?php
                        // The provider's own string, printed exactly as sent and with NO zone label —
                        // it carries none, and appending one would invent a claim about a timestamp
                        // this application did not generate. The two beside it are ours, so they are
                        // shown in the business timezone like every other time on this server.
        ?>
                        <th>Call time</th>
                        <th>Requested</th>
                        <th>Downloaded</th>
                        <th>Recordings</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result->items as $row): ?>
                        <?php $acquisition = $row->acquisition(); ?>
                        <tr>
                            <td>
                                <?= Html::encode($row->storeName !== '' ? $row->storeName : '—') ?>
                                <div class="field__hint">#<?= Html::encode((string) $row->storeSourceId) ?></div>
                            </td>
                            <td>
                                <?= $row->orderId === null
                        ? '<span class="util-muted">—</span>'
                        : Html::encode($row->orderId) ?>
                            </td>
                            <td><code><?= Html::encode($row->callSessionId) ?></code></td>
                            <td><?= Html::encode($row->callTimeRaw) ?></td>
                            <td><?= $when($row->requestedAt()) ?></td>
                            <td><?= $when($row->downloadedAt()) ?></td>
                            <td>
                                <?php
                    // Every channel, with what happened to it and when — enough to see that
                    // the caller side failed at 10:32 while the other two arrived, without
                    // opening anything. Kept to one compact list so the row stays readable.
                        ?>
                                <ul class="o58-channels">
                                    <?php foreach ($channels as $channel): ?>
                                        <?php $item = $row->channel($channel); ?>
                                        <li class="o58-channel">
                                            <span class="o58-channel__name">
                                                <?= Html::encode($channel->label()) ?>
                                            </span>
                                            <?php if ($item === null): ?>
                                                <?php // Never asked for — different from "asked and refused".?>
                                                <span class="util-muted">—</span>
                                            <?php else: ?>
                                                <?php
                                            $state = App\Order58\Domain\RecordingAcquisitionReader::state(
                                                $item->status,
                                            );
                                                ?>
                                                <span class="badge badge--<?= Html::encode($state->badge()) ?>">
                                                    <?= Html::encode($state->label()) ?>
                                                </span>
                                                <?php if ($item->completedAt !== null): ?>
                                                    <span class="o58-channel__at">
                                                        <?= Html::encode(
                                                            $appTimeZone->format($item->completedAt, 'g:i:s A'),
                                                        ) ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                            <td>
                                <?php if ($acquisition !== null): ?>
                                    <?php
                                    // Channels checked, which is what the bar counts everywhere in this
                                    // feature. The sentence under it is what says how many recordings
                                    // actually arrived, because those are different questions.
                                    ?>
                                    <div class="o58-progress">
                                        <span class="o58-progress__count">
                                            <?= Html::encode($acquisition->checked()) ?>
                                            of <?= Html::encode($acquisition->total()) ?>
                                        </span>
                                        <span class="o58-progress__availability">
                                            <?= Html::encode($acquisition->availabilityText()) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($acquisition !== null): ?>
                                    <span class="badge badge--<?= Html::encode($acquisition->outcome()->badge()) ?>">
                                        <?= Html::encode($acquisition->outcome()->label()) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="a2t-slot__link"
                                    href="/audio-to-text/store/<?= Html::encode((string) $row->storeSourceId) ?>">
                                    View store audio
                                </a>
                                <?php
                                // One button per failed channel, never one for the call. A call with the
                                // mixed recording here and the caller side failed must ask again for the
                                // caller side only — the repository would refuse anything else, and
                                // offering a control that does nothing is worse than offering none.
                        ?>
                                <?php foreach ($channels as $channel): ?>
                                    <?php $item = $row->channel($channel); ?>
                                    <?php if ($item !== null && $item->status->isRetryable()): ?>
                                        <form method="post" action="<?= Html::encode($retryUrl) ?>"
                                            class="o58-retry">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="import"
                                                value="<?= Html::encode((string) $item->id) ?>">
                                            <input type="hidden" name="page"
                                                value="<?= Html::encode((string) $page) ?>">
                                            <button class="a2t-slot__link" type="submit">
                                                Retry <?= Html::encode($channel->label()) ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($result->pageCount() > 1): ?>
            <nav class="pager" aria-label="Download history pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn--secondary"
                        href="<?= Html::encode($pageUrl . '?page=' . ($page - 1)) ?>">Previous</a>
                <?php endif; ?>
                <span class="field__hint">
                    Page <?= Html::encode((string) $page) ?> of <?= Html::encode((string) $result->pageCount()) ?>
                </span>
                <?php if ($page < $result->pageCount()): ?>
                    <a class="btn btn--secondary"
                        href="<?= Html::encode($pageUrl . '?page=' . ($page + 1)) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
