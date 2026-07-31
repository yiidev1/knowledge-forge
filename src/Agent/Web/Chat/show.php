<?php

declare(strict_types=1);

use App\Chat\Domain\Conversation;
use App\Chat\Domain\Message;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var KnowledgeBase $knowledgeBase
 * @var Conversation $conversation
 * @var list<Message> $messages
 * @var bool $chatReady
 * @var MarkdownRenderer $markdown
 */

$this->setTitle($conversation->title);

$slug = $knowledgeBase->slug();
$askUrl = $urlGenerator->generate('agent.chat.ask', ['slug' => $slug, 'conversationId' => $conversation->id]);
$allUrl = $urlGenerator->generate('agent.chat.index', ['slug' => $slug]);
$csrfField = (string) $csrf->hiddenInput();
?>
<div class="chat">
    <header class="chat__header">
        <div class="chat__heading">
            <h1 class="chat__title"><?= Html::encode($conversation->title) ?></h1>
            <p class="chat__subtitle"><span><?= Html::encode($knowledgeBase->name()) ?></span></p>
        </div>
        <a class="btn btn--secondary btn--sm" href="<?= Html::encode($allUrl) ?>">← All conversations</a>
    </header>

    <div class="chat__messages" role="log" aria-live="polite" aria-label="Conversation messages" tabindex="0">
        <?php if ($messages === []): ?>
            <div class="chat__empty">
                <div class="chat__empty-icon" aria-hidden="true">💬</div>
                <div class="chat__empty-title">Ask your first question</div>
                <p class="util-muted">Answers come only from this store's indexed documents, with sources.</p>
            </div>
        <?php else: ?>
            <div class="chat-thread">
                <?php foreach ($messages as $message): ?>
                    <?php $isUser = !$message->isAssistant(); ?>
                    <article class="chat-msg chat-msg--<?= $isUser ? 'user' : 'assistant' ?>">
                        <div class="chat-msg__role"><?= $isUser ? 'You' : 'Assistant' ?></div>
                        <div class="chat-msg__body">
                            <?php if ($isUser): ?>
                                <?= nl2br(Html::encode($message->content)) ?>
                            <?php else: ?>
                                <?= $markdown->toHtml($message->content) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($message->isAssistant()): ?>
                            <?php if ($message->citations !== []): ?>
                                <div class="chat-msg__citations">
                                    <span class="chat-msg__citations-label">Sources</span>
                                    <?php foreach ($message->citations as $citation): ?>
                                        <span class="chat-chip"><?= Html::encode($citation->filename) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (!$message->isGrounded): ?>
                                <div class="chat-msg__citations util-muted">No cited source for this answer.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <button type="button" class="chat__jump" hidden>↓ Jump to latest</button>

    <div class="chat__composer">
        <?php if ($chatReady): ?>
            <form method="post" action="<?= Html::encode($askUrl) ?>" class="chat__form">
                <?= $csrfField ?>
                <label class="util-visually-hidden" for="chat-question">Your question</label>
                <textarea class="field__control chat__input" id="chat-question" name="question" rows="1" maxlength="2000"
                          placeholder="Ask a question about this store…" required></textarea>
                <div class="chat__composer-actions">
                    <span class="chat__hint" aria-hidden="true">Enter to send · Shift+Enter for a new line</span>
                    <button class="btn btn--primary" type="submit">Send</button>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert--info">
                <span class="alert__icon" aria-hidden="true">i</span>
                <span>This store is not currently available for chat.</span>
            </div>
        <?php endif; ?>
    </div>
</div>
