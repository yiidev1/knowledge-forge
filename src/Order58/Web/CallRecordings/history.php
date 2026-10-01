<?php

declare(strict_types=1);

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportHistoryPage;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;

/**
 * @var Yiisoft\View\WebView $this
 * @var CallImportHistoryPage $result
 * @var int $page
 * @var list<RecordingChannel> $channels
 * @var AppTimeZone $appTimeZone
 * @var string $pageUrl
 * @var string $recordingsUrl
 */

$this->setTitle('Download History');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Order58 Call Recordings', 'route' => 'order58.call-recordings'],
    ['label' => 'Download History'],
]);


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
        <div class="table-wrap o58-wide">
            <table class="table o58-table">
                <thead>
                    <tr>
                        <th class="o58-table__store">Store</th>
                        <th class="o58-history__order">Order ID</th>
                        <th class="o58-history__id">Call session ID</th>
                        <?php
                        // The provider's own string, printed exactly as sent and with NO zone label —
                        // it carries none, and appending one would invent a claim about a timestamp
                        // this application did not generate. The two beside it are ours, so they are
                        // shown in the business timezone like every other time on this server.
        ?>
                        <th class="o58-history__time">Call time</th>
                        <th class="o58-table__when">Requested</th>
                        <th class="o58-table__when">Downloaded</th>
                        <th>Recordings</th>
                        <th class="o58-table__progress">Progress</th>
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
                                        <?php
                                        $state = $item === null
                                            ? null
                                            : App\Order58\Domain\RecordingAcquisitionReader::state($item->status);
                                        ?>
                                        <li class="o58-channel"
                                            data-state="<?= Html::encode($state?->step() ?? 'pending') ?>">
                                            <span class="o58-channel__mark" aria-hidden="true"></span>
                                            <span class="o58-channel__name">
                                                <?= Html::encode($channel->label()) ?>
                                            </span>
                                            <?php if ($item === null || $state === null): ?>
                                                <?php // Never asked for — different from "asked and refused".?>
                                                <span class="util-muted">—</span>
                                            <?php else: ?>
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
                                <?php if ($acquisition !== null && $acquisition->isActive()): ?>
                                    <?php
                                    // Still moving, so the same panel the live page draws — the only
                                    // rows in a history that have anything to show a bar about.
                                    ?>
                                    <div class="o58-recordings">
                                        <div class="o58-panel">
                                            <div class="o58-panel__head">
                                                <span class="o58-panel__title">Overall progress</span>
                                                <span class="o58-panel__count" aria-hidden="true">
                                                    <?= Html::encode($acquisition->progressText()) ?>
                                                </span>
                                            </div>
                                            <progress class="o58-panel__bar" max="100"
                                                value="<?= $acquisition->percentChecked() ?>"
                                                aria-label="<?= Html::encode($acquisition->progressText()) ?>">
                                            </progress>
                                        </div>
                                        <span class="o58-recordings__availability">
                                            <?= Html::encode($acquisition->availabilityText()) ?>
                                        </span>
                                    </div>
                                <?php elseif ($acquisition !== null): ?>
                                    <?php
                                    // Settled. One line saying what came of it, and nothing else —
                                    // a bar here would be tracking something that finished, often days
                                    // ago, and a card around one sentence is a box for its own sake.
                                    ?>
                                    <span class="o58-recordings__availability">
                                        <?= Html::encode($acquisition->availabilityText()) ?>
                                    </span>
                                <?php endif; ?>
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
