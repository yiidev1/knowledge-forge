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
 * @var bool $provisioned
 * @var int $readyDocuments
 */

$this->setTitle('Chat · ' . $knowledgeBase->name());
$this->setParameter('breadcrumbs', [
    ['label' => 'Store chat', 'route' => 'order58.store-chat'],
    ['label' => $knowledgeBase->name()],
    ['label' => 'Chat'],
]);

$slug = $knowledgeBase->slug();
$startUrl = $urlGenerator->generate('chat.start', ['slug' => $slug]);
$csrfField = (string) $csrf->hiddenInput();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Chat</h1>
        <p class="page-header__subtitle util-mono">/<?= Html::encode($slug) ?></p>
    </div>
    <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.store-chat')) ?>">← Store chat</a>
</div>

<div class="card">
    <?php if ($chatReady): ?>
        <h2 class="card__title">Ask a question</h2>
        <p class="field__hint" style="margin-top: -0.5rem;">
            Answers come only from this knowledge base's indexed documents, with citations.
        </p>
        <form method="post" action="<?= Html::encode($startUrl) ?>" style="max-width: 720px;">
            <?= $csrfField ?>
            <div class="field">
                <textarea class="field__control" name="question" rows="3" maxlength="2000"
                          placeholder="e.g. What is the refund policy?" required></textarea>
            </div>
            <button class="btn btn--primary" type="submit">Start conversation</button>
        </form>
    <?php elseif (!$provisioned): ?>
        <div class="alert alert--info">
            <span class="alert__icon">i</span>
            <span>This knowledge base is still being provisioned. Chat becomes available once it is ready.</span>
        </div>
    <?php else: ?>
        <div class="alert alert--info">
            <span class="alert__icon">i</span>
            <span>No documents have finished indexing yet. Upload and index a document before asking a question.</span>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card__title">Conversations</h2>
    <?php if ($conversations === []): ?>
        <div class="empty" style="padding: 1.5rem;">
            <div class="empty__title">No conversations yet</div>
            <p>Start one above to ask this knowledge base a question.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Conversation</th><th style="width: 200px;">Last activity</th></tr></thead>
                <tbody>
                    <?php foreach ($conversations as $conversation): ?>
                        <?php $showUrl = $urlGenerator->generate('chat.show', ['slug' => $slug, 'conversationId' => $conversation->id]); ?>
                        <tr>
                            <td><a href="<?= Html::encode($showUrl) ?>"><?= Html::encode($conversation->title) ?></a></td>
                            <td class="util-muted">
                                <?= Html::encode($conversation->lastMessageAt?->format('Y-m-d H:i') ?? '—') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
