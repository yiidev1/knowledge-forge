<?php

declare(strict_types=1);

use App\Chat\Domain\Conversation;
use App\KnowledgeBase\Domain\KnowledgeBase;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var KnowledgeBase $knowledgeBase
 * @var list<Conversation> $conversations
 * @var bool $chatReady
 */

$this->setTitle($knowledgeBase->name());

$slug = $knowledgeBase->slug();
$startUrl = $urlGenerator->generate('agent.chat.start', ['slug' => $slug]);
$homeUrl = $urlGenerator->generate('agent.home');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($knowledgeBase->name()) ?></h1>
        <p class="page-header__subtitle">Answers come only from this store's indexed documents, with sources.</p>
    </div>
    <a class="btn btn--secondary" href="<?= Html::encode($homeUrl) ?>">← All stores</a>
</div>

<section class="card">
    <h2 class="card__title">Ask a new question</h2>
    <?php if ($chatReady): ?>
        <form method="post" action="<?= Html::encode($startUrl) ?>" class="chat__form">
            <?= $csrf->hiddenInput() ?>
            <label class="util-visually-hidden" for="chat-question">Your question</label>
            <textarea class="field__control chat__input" id="chat-question" name="question" rows="2" maxlength="2000"
                      placeholder="Ask a question about this store…" required></textarea>
            <div class="chat__composer-actions">
                <span class="chat__hint" aria-hidden="true">Enter to send · Shift+Enter for a new line</span>
                <button class="btn btn--primary" type="submit">Start chat</button>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert--info">
            <span class="alert__icon" aria-hidden="true">i</span>
            <span>This store has no indexed documents yet, so it cannot be chatted with.</span>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card__title">Your conversations</h2>
    <?php if ($conversations === []): ?>
        <p class="util-muted">You have no conversations for this store yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Conversation</th><th>Last activity</th></tr></thead>
                <tbody>
                    <?php foreach ($conversations as $conversation): ?>
                        <?php $showUrl = $urlGenerator->generate('agent.chat.show', ['slug' => $slug, 'conversationId' => $conversation->id]); ?>
                        <tr>
                            <td><a href="<?= Html::encode($showUrl) ?>"><strong><?= Html::encode($conversation->title) ?></strong></a></td>
                            <td class="util-muted"><?= Html::encode($conversation->lastMessageAt?->format('Y-m-d H:i') ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
