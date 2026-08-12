<?php

declare(strict_types=1);

use App\KnowledgeBase\Domain\Rule;
use App\Rules\Domain\StoreRuleItem;
use Yiisoft\Html\Html;

/**
 * "Rules available to this chat" for a STORE chat — shared by the admin and agent surfaces.
 *
 * Two genuinely different things are shown, and the page never blurs them:
 *
 *  1. Answering rules — {@see \App\KnowledgeBase\Domain\Rule} rows injected verbatim into this chat's prompt by
 *     {@see \App\Chat\Application\Instruction\InstructionBuilder}. These really do shape every answer.
 *  2. Order58 catalog rules classified as applying to this store. Store Chat CANNOT retrieve these: store rule
 *     projections were retired, and {@see \App\Chat\Domain\ChatRetrievalScope::StoreKnowledge} drops a rule
 *     citation even if one appeared. They are listed for reference, clearly marked, with Rule Chat named as
 *     the surface that can actually answer them.
 *
 * @var Yiisoft\View\WebView $this
 * @var string $title
 * @var string $contextName
 * @var list<Rule> $answeringRules
 * @var list<StoreRuleItem> $catalogRules
 * @var string|null $ruleChatUrl
 * @var string $backUrl
 * @var string $backLabel
 */

$this->setTitle($title);
$enabledRules = array_values(array_filter($answeringRules, static fn(Rule $r): bool => $r->isEnabled()));
$catalogCount = count($catalogRules);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Rules available to this chat</h1>
        <p class="page-header__subtitle">
            How rules apply when this chat answers about <strong><?= Html::encode($contextName) ?></strong>.
        </p>
    </div>
    <a class="btn btn--secondary btn--sm" href="<?= Html::encode($backUrl) ?>">← <?= Html::encode($backLabel) ?></a>
</div>

<section class="card">
    <h2 class="card__title">Answering rules applied to every reply</h2>
    <p class="util-muted">
        Added to this chat's instructions before the question is asked. They control how an answer is worded —
        they are not searchable content and are never cited.
    </p>

    <?php if ($enabledRules === []): ?>
        <p class="util-muted">No answering rules are configured, so only the built-in grounding instructions apply.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--sources">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Rule</th>
                        <th>Instruction</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 1; ?>
                    <?php foreach ($enabledRules as $rule): ?>
                        <tr>
                            <td class="util-muted"><?= $n++ ?></td>
                            <td><?= Html::encode($rule->name()) ?></td>
                            <td class="util-muted"><?= Html::encode($rule->instruction()) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" style="margin-top: 1.25rem;">
    <div class="util-row" style="justify-content: space-between; align-items: baseline;">
        <h2 class="card__title" style="margin: 0;">
            Order58 rules linked to this store
            <span class="util-muted">(<?= $catalogCount ?>)</span>
        </h2>
        <?php if ($ruleChatUrl !== null): ?>
            <a class="btn btn--secondary btn--sm" href="<?= Html::encode($ruleChatUrl) ?>">Ask in Rule Chat</a>
        <?php endif; ?>
    </div>

    <p class="util-muted">
        <strong>This chat cannot answer from these rules.</strong> Store chat searches only this store's own
        knowledge documents; rule content is answered by Rule Chat. They are listed here so you can see which
        rules the catalog considers applicable to this store.
    </p>

    <?php if ($catalogRules === []): ?>
        <p class="util-muted">No active Order58 rules are linked to this store.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--sources">
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Canonical id</th>
                        <th>Scope</th>
                        <th>Classification</th>
                        <th>Applies because</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catalogRules as $rule): ?>
                        <tr>
                            <td>
                                <?php if ($rule->hasContent()): ?>
                                    <details class="src-detail">
                                        <summary><?= Html::encode($rule->title) ?></summary>
                                        <div class="src-detail__body"><?= Html::encode((string) $rule->content) ?></div>
                                    </details>
                                <?php else: ?>
                                    <?= Html::encode($rule->title) ?>
                                <?php endif; ?>
                            </td>
                            <td class="util-muted">#<?= $rule->canonicalId ?></td>
                            <td><?= Html::encode($rule->scopeLabel()) ?></td>
                            <td class="util-muted"><?= Html::encode($rule->classificationLabel) ?></td>
                            <td class="util-muted"><?= Html::encode($rule->matchLabel()) ?></td>
                            <td class="util-muted"><?= Html::encode($rule->updatedAt) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
