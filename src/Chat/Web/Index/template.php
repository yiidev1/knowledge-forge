<?php

declare(strict_types=1);

use App\Chat\Domain\Conversation;
use App\Chat\Domain\Message;
use App\Chat\Web\ChatPartials;
use App\Chat\Web\MessageEditView;
use App\Chat\Web\MessageScoreView;
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
 * @var bool $provisioned
 * @var string|null $unavailableMessage
 * @var MarkdownRenderer $markdown
 * @var MessageEditView $editView
 * @var MessageScoreView $scoreView
 * @var int $maxQuestionLength
 */

$this->setTitle('Chat · ' . $knowledgeBase->name());
$this->setParameter('breadcrumbs', [
    ['label' => 'Store chat', 'route' => 'order58.store-chat'],
    ['label' => $knowledgeBase->name()],
    ['label' => 'Chat'],
]);

$slug = $knowledgeBase->slug();
$postUrl = $urlGenerator->generate('chat.start', ['slug' => $slug]);
$historyUrl = $urlGenerator->generate('chat.history', ['slug' => $slug]);
$knowledgeUrl = $urlGenerator->generate('chat.sources.knowledge', ['slug' => $slug]);
$csrfField = (string) $csrf->hiddenInput();
$vs = $knowledgeBase->vectorStoreStatus();
$oldestId = $messages !== [] ? $messages[0]->id : null;
?>
<div class="chat" data-chat-root data-history-url="<?= Html::encode($historyUrl) ?>">
    <header class="chat__header">
        <div class="chat__heading">
            <h1 class="chat__title"><?= Html::encode($knowledgeBase->name()) ?></h1>
            <p class="chat__subtitle">
                <span class="util-mono">/<?= Html::encode($slug) ?></span>
                <span class="chat__dot" aria-hidden="true">·</span>
                <span class="badge badge--<?= Html::encode($vs->badge()) ?>"><span class="badge__dot"></span><?= Html::encode($vs->label()) ?></span>
            </p>
        </div>
        <div class="chat__header-actions">
            <a class="btn btn--secondary btn--sm" href="<?= Html::encode($knowledgeUrl) ?>">View Knowledge</a>
            <a class="btn btn--secondary btn--sm" href="<?= Html::encode($urlGenerator->generate('order58.store-chat')) ?>">← Store chat</a>
        </div>
    </header>

    <div class="chat__messages" role="log" aria-live="polite" aria-label="Conversation messages" tabindex="0" data-chat-messages>
        <?php if ($hasOlder && $oldestId !== null): ?>
            <div class="chat__load-older">
                <button type="button" class="btn btn--secondary btn--sm" data-load-older data-before-id="<?= $oldestId ?>">
                    Load older messages
                </button>
            </div>
        <?php endif; ?>

        <?php if ($messages === []): ?>
            <div class="chat__empty" data-chat-empty>
                <div class="chat__empty-icon" aria-hidden="true">💬</div>
                <div class="chat__empty-title">Ask your first question</div>
                <p class="util-muted">Answers come only from this knowledge base's indexed documents, with sources.</p>
                <?php if (!$chatReady && $unavailableMessage !== null): ?>
                    <p class="util-muted"><?= Html::encode($unavailableMessage) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="chat-thread" data-chat-thread>
                <?php foreach ($messages as $message): ?>
                    <?php
                    $isUser = !$message->isAssistant();
                    $ts = $message->createdAt->format('Y-m-d H:i');
                    $isEditable = $isUser && $conversation !== null && $editView->isEditable($message->id);
                    $isRetry = $isUser && $conversation !== null && $editView->isRetry($message->id);
                    ?>
                    <article class="chat-msg chat-msg--<?= $isUser ? 'user' : 'assistant' ?>" data-message-id="<?= $message->id ?>">
                        <div class="chat-msg__role"><?= $isUser ? 'You' : 'Assistant' ?></div>
                        <div class="chat-msg__body" data-msg-body>
                            <?php if ($isUser): ?>
                                <?= nl2br(Html::encode($message->content)) ?>
                            <?php else: ?>
                                <?= $markdown->toHtml($message->content) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($isEditable): ?>
                            <button type="button" class="chat-msg__edit-icon" data-edit-toggle title="Edit question" aria-label="Edit question">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                            </button>
                            <form method="post" class="chat-msg__edit" data-edit-form
                                  action="<?= Html::encode($urlGenerator->generate('chat.message.edit', ['slug' => $slug, 'conversationId' => $conversation->id, 'messageId' => $message->id])) ?>">
                                <?= $csrfField ?>
                                <input type="hidden" name="expected_edit_count" value="<?= $message->editCount ?>">
                                <label class="util-visually-hidden" for="edit-<?= $message->id ?>">Edit your question</label>
                                <textarea class="field__control chat__input" id="edit-<?= $message->id ?>" name="content" rows="2"
                                          maxlength="<?= $maxQuestionLength ?>" required><?= Html::encode($message->content) ?></textarea>
                                <div class="chat-msg__edit-actions">
                                    <button class="btn btn--primary btn--sm" type="submit">Save &amp; regenerate</button>
                                    <button class="btn btn--secondary btn--sm" type="button" data-edit-cancel>Cancel</button>
                                </div>
                            </form>
                        <?php endif; ?>
                        <div class="chat-msg__time">
                            <?= Html::encode($ts) ?>
                            <?php if ($message->isEdited()): ?><span class="chat-msg__edited" title="This question was edited">· Edited</span><?php endif; ?>
                        </div>
                        <?php if ($isRetry): ?>
                            <form method="post" class="chat-msg__retry" data-retry-form
                                  action="<?= Html::encode($urlGenerator->generate('chat.message.regenerate', ['slug' => $slug, 'conversationId' => $conversation->id, 'messageId' => $message->id])) ?>">
                                <?= $csrfField ?>
                                <span class="chat-msg__retry-note">The answer couldn’t be generated.</span>
                                <button class="btn btn--secondary btn--sm" type="submit">Retry</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($message->isAssistant()): ?>
                            <?php if ($message->citations !== []): ?>
                                <div class="chat-msg__citations">
                                    <span class="chat-msg__citations-label">Sources (<?= count($message->citations) ?>)</span>
                                    <?php foreach ($message->citations as $citation): ?>
                                        <?php /* The URL is built here, with the conversation and message already
                                                 bound to it; the browser never composes a source address. It is
                                                 a convenience only — the server re-checks every id. */ ?>
                                        <?php if ($conversation !== null): ?>
                                            <button type="button" class="chat-chip chat-chip--source"
                                                    data-source-url="<?= Html::encode($urlGenerator->generate('chat.message.source', ['slug' => $slug, 'conversationId' => $conversation->id, 'messageId' => $message->id, 'documentId' => $citation->documentId])) ?>"
                                                    title="View this source"><?= Html::encode($citation->filename) ?></button>
                                        <?php else: ?>
                                            <span class="chat-chip"><?= Html::encode($citation->filename) ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (!$message->isGrounded): ?>
                                <div class="chat-msg__citations util-muted">No cited source for this answer.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($message->isAssistant() && $conversation !== null): ?>
                            <?= $this->render(ChatPartials::scorePanel(), [
                                'messageId' => $message->id,
                                'state' => $scoreView->stateFor($message->id),
                                'scoreUrl' => $urlGenerator->generate('chat.message.score', ['slug' => $slug, 'conversationId' => $conversation->id, 'messageId' => $message->id]),
                                'dismissUrl' => $urlGenerator->generate('chat.message.score.dismiss', ['slug' => $slug, 'conversationId' => $conversation->id, 'messageId' => $message->id]),
                                'csrfField' => $csrfField,
                            ]) ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <button type="button" class="chat__jump" hidden>↓ Jump to latest</button>

    <div class="chat__composer">
        <?php if ($editView->hasBlockedComposer()): ?>
            <div class="alert alert--warning" data-composer-blocked>
                <span class="alert__icon" aria-hidden="true">!</span>
                <span>The previous answer couldn’t be generated. Use Retry above to finish it before asking a new question.</span>
            </div>
        <?php elseif ($chatReady): ?>
            <form method="post" action="<?= Html::encode($postUrl) ?>" class="chat__form">
                <?= $csrfField ?>
                <label class="util-visually-hidden" for="chat-question">Your question</label>
                <input class="field__control chat__input" type="text" id="chat-question" name="question"
                       maxlength="<?= $maxQuestionLength ?>" placeholder="Ask a question about this knowledge base…"
                       autocomplete="off" required>
                <div class="chat__composer-actions">
                    <button class="btn btn--primary" type="submit">Send</button>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert--info" data-composer-unavailable>
                <span class="alert__icon" aria-hidden="true">i</span>
                <span><?= Html::encode($unavailableMessage ?? 'This knowledge base is not currently available for chat.') ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php /* One dialog per page, filled in on demand by whichever source chip is clicked. */ ?>
<?= $this->render(ChatPartials::sourceModal()) ?>
