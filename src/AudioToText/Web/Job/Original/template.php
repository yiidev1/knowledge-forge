<?php

declare(strict_types=1);

use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\AudioToTextViews;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var TranscriptionJob $job
 * @var ConversationView $conversation the machine's own, never the reviewed layer
 * @var AudioStore|null $store
 */

$this->setTitle('Original transcript');
$this->setParameter('breadcrumbs', array_values(array_filter([
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    $store === null ? null : [
        'label' => $store->name,
        'route' => AudioToTextRoute::STORE,
        'arguments' => ['sourceId' => $store->sourceId],
    ],
    ['label' => 'Conversions', 'route' => AudioToTextRoute::JOBS],
    ['label' => 'Original transcript'],
])));

$reviewUrl = $urlGenerator->generate(AudioToTextRoute::JOB_REVIEW, ['publicId' => $job->publicId]);
$jobUrl = $urlGenerator->generate(AudioToTextRoute::JOB, ['publicId' => $job->publicId]);
?>
<div class="a2t-chat">
    <div class="a2t-chat__header">
        <div class="a2t-chat__heading">
            <h1 class="a2t-chat__title">Original transcript</h1>
            <p class="a2t-chat__subtitle">
                <?= Html::encode($job->originalFilename) ?> — as the system produced it, before any
                correction. This page never changes.
                <?php if (!$conversation->rolesPublished): ?>
                    <?php
                    // The machine's own verdict, and it stays this way however the roles were confirmed
                    // afterwards: that confirmation is about the corrected conversation, not this one.
                    ?>
                    The system could not tell which speaker is the agent, so the speakers are shown as
                    Speaker&nbsp;1 and Speaker&nbsp;2.
                <?php endif; ?>
            </p>
        </div>

        <?php // Outside the scrolling container, so it stays put while the messages move.?>
        <div class="a2t-chat__actions">
            <?php if ($store !== null): ?>
                <a class="btn" href="<?= Html::encode($urlGenerator->generate(
                    AudioToTextRoute::STORE,
                    ['sourceId' => $store->sourceId],
                )) ?>">Back to <?= Html::encode($store->name) ?></a>
            <?php endif; ?>
            <?php // The point of this page is the comparison, so the way to the current version is here.?>
            <a class="btn" href="<?= Html::encode($reviewUrl) ?>">Current version</a>
            <a class="btn btn--primary" href="<?= Html::encode($jobUrl) ?>">Full conversion details</a>
        </div>
    </div>

    <?php
    // The same partial the detail and conversation pages render, so a turn reads identically wherever
    // it appears. It emits no data-a2t-role and this page carries no .a2t-review, so neither the
    // correction controls nor the agent tint can apply here — both are scoped to the review page.
    ?>
    <div class="a2t-chat__scroll" data-a2t-scroll>
        <?= $this->render(AudioToTextViews::thread(), ['turns' => $conversation->turns]) ?>
    </div>

    <button class="a2t-chat__jump" type="button" data-a2t-jump hidden>&darr; Jump to latest</button>
</div>
