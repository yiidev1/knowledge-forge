<?php

declare(strict_types=1);

use App\AudioToText\Domain\Speaker\ConversationTurn;
use Yiisoft\Html\Html;

/**
 * The conversation itself — bubbles, labels, timing and markers.
 *
 * Shared by the job detail page and the conversation-only page so there is exactly one rendering of a
 * turn. Two copies would drift, and a speaker reading one way on one screen and another way on the
 * next is the specific failure this whole feature exists to prevent.
 *
 * It renders and decides nothing: side, label, confirmed-ness, timing and markers are all settled by
 * `ConversationView` before they arrive. A template choosing who sits on the right would be choosing
 * who the agent is.
 *
 * @var Yiisoft\View\WebView $this
 * @var list<ConversationTurn> $turns
 */
?>
<div class="a2t-thread">
    <?php foreach ($turns as $turn): ?>
        <?php
        $range = $turn->timing->rangeLabel();
        $delay = $turn->timing->delayLabel();
        ?>
        <div class="a2t-turn <?= Html::encode($turn->side->modifier()) ?><?=
            $turn->confirmed ? '' : ' a2t-turn--unconfirmed' ?>">
            <div class="a2t-bubble">
                <span class="a2t-turn__who"><?= Html::encode($turn->label) ?></span>
                <span class="a2t-turn__text"><?= Html::encode($turn->text) ?></span>
                <?php if ($range !== null || $delay !== null || $turn->edited): ?>
                    <span class="a2t-turn__meta">
                        <?php if ($range !== null): ?>
                            <?php
                            // A tilde marks a span a person's split produced rather than one the
                            // machine measured. Without it the two are indistinguishable on screen.
                            ?>
                            <span class="a2t-turn__time"<?= $turn->timing->approximate
                                ? ' title="Approximate: this boundary was set by hand, so both halves keep the original turn\'s timing."'
                                : '' ?>><?= Html::encode($range) ?></span>
                        <?php endif; ?>
                        <?php if ($delay !== null): ?>
                            <span class="a2t-turn__delay"><?= Html::encode($delay) ?></span>
                        <?php endif; ?>
                        <?php if ($turn->edited): ?>
                            <span class="a2t-turn__flag">edited</span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
