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
 * @var Conversation|null $conversation
 * @var list<Message> $messages
 * @var bool $hasOlder
 * @var bool $chatReady
 * @var MarkdownRenderer $markdown
 */

$this->setTitle($knowledgeBase->name());

$slug = $knowledgeBase->slug();
$postUrl = $urlGenerator->generate('agent.chat.start', ['slug' => $slug]);
$historyUrl = $urlGenerator->generate('agent.chat.history', ['slug' => $slug]);
$homeUrl = $urlGenerator->generate('agent.home');
$csrfField = (string) $csrf->hiddenInput();
$oldestId = $messages !== [] ? $messages[0]->id : null;
?>
<div class="chat" data-chat-root data-history-url="<?= Html::encode($historyUrl) ?>">
    <header class="chat__header">
        <div class="chat__heading">
            <h1 class="chat__title"><?= Html::encode($knowledgeBase->name()) ?></h1>
            <p class="chat__subtitle">Answers come only from this store's indexed documents, with sources.</p>
        </div>
        <a class="btn btn--secondary btn--sm" href="<?= Html::encode($homeUrl) ?>">← Stores</a>
    </header>

    <div class="chat__messages" role="log" aria-live="polite" aria-label="Conversation messages" tabindex="0" data-chat-messages>
        <?php if ($hasOlder && $oldestId !== null): ?>
            <div class="chat__load-older">
                <button type="button" class="btn btn--secondary btn--sm" data-load-older data-before-id="<?= (int) $oldestId ?>">
                    Load older messages
                </button>
            </div>
        <?php endif; ?>

        <?php if ($messages === []): ?>
            <div class="chat__empty" data-chat-empty>
                <div class="chat__empty-icon" aria-hidden="true">💬</div>
                <div class="chat__empty-title">Ask your first question</div>
                <p class="util-muted">Your chat with this store is private to you.</p>
            </div>
        <?php else: ?>
            <div class="chat-thread" data-chat-thread>
                <?php foreach ($messages as $message): ?>
                    <?php
                    $isUser = !$message->isAssistant();
                    $ts = $message->createdAt->format('Y-m-d H:i');
                    ?>
                    <article class="chat-msg chat-msg--<?= $isUser ? 'user' : 'assistant' ?>" data-message-id="<?= (int) $message->id ?>">
                        <div class="chat-msg__role"><?= $isUser ? 'You' : 'Assistant' ?></div>
                        <div class="chat-msg__body">
                            <?php if ($isUser): ?>
                                <?= nl2br(Html::encode($message->content)) ?>
                            <?php else: ?>
                                <?= $markdown->toHtml($message->content) ?>
                            <?php endif; ?>
                        </div>
                        <div class="chat-msg__time"><?= Html::encode($ts) ?></div>
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
            <form method="post" action="<?= Html::encode($postUrl) ?>" class="chat__form">
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
