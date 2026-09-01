<?php

declare(strict_types=1);

use App\AudioToText\Domain\EffectiveConversation;
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
 * @var ConversationView $conversation
 * @var EffectiveConversation $effective
 */

$this->setTitle('Conversation');
$this->setParameter('breadcrumbs', [
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    ['label' => 'Conversions', 'route' => AudioToTextRoute::JOBS],
    ['label' => 'Conversation'],
]);

$jobUrl = $urlGenerator->generate(AudioToTextRoute::JOB, ['publicId' => $job->publicId]);
$reviewUrl = $urlGenerator->generate(AudioToTextRoute::JOB_REVIEW, ['publicId' => $job->publicId]);
?>
<?php
// `.a2t-chat` is what the stylesheet looks for to switch this page to a fixed-height layout: the
// header stays put and the messages scroll inside their own container, rather than the whole window
// scrolling and taking the header with it.
?>
<div class="a2t-chat">
    <div class="a2t-chat__header">
        <div class="a2t-chat__heading">
            <h1 class="a2t-chat__title"><?= Html::encode($job->originalFilename) ?></h1>
            <p class="a2t-chat__subtitle">
                <?php if ($conversation->rolesPublished): ?>
                    <?= $job->rolesConfirmedAt !== null
                        ? 'Speaker roles confirmed by an administrator.'
                        : 'Speakers separated by the system.' ?>
                <?php else: ?>
                    <?php // The same caution the detail page shows, in one line: this is not a finding.?>
                    Roles are not confirmed, so the speakers are shown as Speaker&nbsp;1 and Speaker&nbsp;2.
                <?php endif; ?>
                <?php if ($effective->isReviewed): ?>
                    Corrected by an administrator.
                <?php endif; ?>
            </p>
        </div>

        <?php // Stays visible while the messages scroll — it is outside the scrolling container.?>
        <div class="a2t-chat__actions">
            <a class="btn" href="<?= Html::encode($reviewUrl) ?>">Correct conversation</a>
            <a class="btn btn--primary" href="<?= Html::encode($jobUrl) ?>">Full conversion details</a>
        </div>
    </div>

    <div class="a2t-chat__scroll" data-a2t-scroll>
        <?= $this->render(AudioToTextViews::thread(), ['turns' => $conversation->turns]) ?>
    </div>

    <?php // Shown by the script only once the reader has scrolled away from the newest turn.?>
    <button class="a2t-chat__jump" type="button" data-a2t-jump hidden>&darr; Jump to latest</button>
</div>
