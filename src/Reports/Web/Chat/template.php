<?php

declare(strict_types=1);

use App\Reports\Domain\AgentUsageRow;
use App\Reports\Domain\AnswerStatusFilter;
use App\Reports\Domain\ChatReportQuery;
use App\Reports\Domain\ChatReportResult;
use App\Reports\Domain\ChatReportRow;
use App\Reports\Domain\ChatReportSummary;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\FeedbackFilter;
use App\Reports\Domain\RatingFilter;
use App\Reports\Domain\ReportDateRange;
use App\Reports\Domain\ScoreDisplay;
use App\Reports\Domain\StoreUsageRow;
use App\Reports\Domain\UsageResult;
use App\Reports\Web\Chat\ReportViews;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var ChatReportQuery $query
 * @var ReportDateRange $range
 * @var ChatReportSummary $summary
 * @var UsageResult<AgentUsageRow> $agents
 * @var UsageResult<StoreUsageRow> $stores
 * @var ChatReportResult $result
 * @var ChatReportRow|null $detail
 * @var list<array{id: int, label: string}> $agentOptions
 * @var list<array{id: int, label: string}> $storeOptions
 * @var list<array{label: string, from: string, to: string, active: bool}> $presets
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Chat Reports');
$this->setParameter('breadcrumbs', [
    ['label' => 'Chat Reports'],
]);

$base = $urlGenerator->generate('admin.reports.chat');
$detailBase = $urlGenerator->generate('admin.reports.chat.detail');

$agentPage = $agents->currentPage();
$storePage = $stores->currentPage();
$qaPage = $result->currentPage();

/**
 * Rebuilds a report URL with some state replaced, dropping anything at its default so links stay readable.
 * The three page numbers are independent keys, so moving one table never carries another with it.
 */
$stateUrl = static function (array $overrides, string $target) use (
    $base,
    $detailBase,
    $query,
    $range,
    $agentPage,
    $storePage,
    $qaPage
): string {
    $state = [
        'from' => $range->from,
        'to' => $range->to,
        'type' => $query->chatType->value,
        'rating' => $query->rating->value,
        'feedback' => $query->feedback->value,
        'status' => $query->status->value,
        'agent' => $query->agentAdminId === null ? '' : (string) $query->agentAdminId,
        'store' => $query->knowledgeBaseId === null ? '' : (string) $query->knowledgeBaseId,
        'q' => $query->search,
        'sort' => $query->agentSort->field,
        'dir' => $query->agentSort->direction(),
        'ssort' => $query->storeSort->field,
        'sdir' => $query->storeSort->direction(),
        'agent_page' => (string) $agentPage,
        'store_page' => (string) $storePage,
        'qa_page' => (string) $qaPage,
    ];
    foreach ($overrides as $key => $value) {
        $state[$key] = (string) $value;
    }

    $params = ['from' => $state['from'], 'to' => $state['to']];
    foreach (
        [
            'type' => ChatTypeFilter::All->value,
            'rating' => RatingFilter::All->value,
            'feedback' => FeedbackFilter::All->value,
            'status' => AnswerStatusFilter::All->value,
        ] as $key => $default
    ) {
        if ($state[$key] !== $default) {
            $params[$key] = $state[$key];
        }
    }
    foreach (['agent', 'store', 'q'] as $key) {
        if ($state[$key] !== '') {
            $params[$key] = $state[$key];
        }
    }
    if ($state['sort'] !== 'questions' || $state['dir'] !== 'desc') {
        $params['sort'] = $state['sort'];
        $params['dir'] = $state['dir'];
    }
    if ($state['ssort'] !== 'questions' || $state['sdir'] !== 'desc') {
        $params['ssort'] = $state['ssort'];
        $params['sdir'] = $state['sdir'];
    }
    foreach (['agent_page', 'store_page', 'qa_page'] as $key) {
        if ((int) $state[$key] > 1) {
            $params[$key] = $state[$key];
        }
    }
    // Deliberately not part of the carried state: a single record is opened explicitly and left behind by
    // every other link, so paging or refiltering returns to the report rather than pinning one question.
    if (($state['question'] ?? '') !== '') {
        $params['question'] = $state['question'];
    }

    return ($target === 'detail' ? $detailBase : $base) . '?' . http_build_query($params);
};

$reportUrl = static fn(array $overrides): string => $stateUrl($overrides, 'page');

/**
 * A drill-down target. The href is the report page with the metric's own filters applied — a genuinely
 * usable view without JavaScript — and the data attribute is the same filters against the JSON endpoint,
 * which is what makes the dialog's rows and the number that opened it impossible to disagree.
 */
$drill = static function (array $filters, string $label, string $context) use ($stateUrl): array {
    $filters['agent_page'] = 1;
    $filters['store_page'] = 1;
    $filters['qa_page'] = 1;

    return [
        'href' => $stateUrl($filters, 'page'),
        'json' => $stateUrl($filters, 'detail'),
        'label' => $label,
        'context' => $context,
    ];
};

/** Renders a metric cell as a keyboard-reachable link; a zero is plain text, since there is nothing behind it. */
$metric = static function (int|string $value, array $filters, string $label, string $context) use ($drill): string {
    if ((string) $value === '0' || (string) $value === '—') {
        return '<span class="util-muted">' . Html::encode((string) $value) . '</span>';
    }

    $target = $drill($filters, $label, $context);

    return '<a class="report__metric" href="' . Html::encode($target['href']) . '"'
        . ' data-report-drill="' . Html::encode($target['json']) . '"'
        . ' data-report-label="' . Html::encode($target['label']) . '"'
        . ' data-report-context="' . Html::encode($target['context']) . '">'
        . Html::encode((string) $value) . '</a>';
};

$dash = static fn(?string $v): string => $v === null || $v === '' ? '—' : Html::encode($v);
$localTime = static fn(?DateTimeImmutable $d): string => $d === null ? '—' : $appTimeZone->format($d, 'M j, Y g:i A');
$rating = static fn(?float $v): string => $v === null ? '—' : sprintf('%.1f', $v);

$agentHeader = static function (string $field, string $label) use ($query, $reportUrl): string {
    $href = $reportUrl([
        'sort' => $field,
        'dir' => $query->agentSort->nextDirectionFor($field),
        'agent_page' => 1,
    ]);

    return '<th aria-sort="' . Html::encode($query->agentSort->ariaFor($field)) . '">'
        . '<a href="' . Html::encode($href) . '">'
        . Html::encode($label . $query->agentSort->markerFor($field)) . '</a></th>';
};

$storeHeader = static function (string $field, string $label) use ($query, $reportUrl): string {
    $href = $reportUrl([
        'ssort' => $field,
        'sdir' => $query->storeSort->nextDirectionFor($field),
        'store_page' => 1,
    ]);

    return '<th aria-sort="' . Html::encode($query->storeSort->ariaFor($field)) . '">'
        . '<a href="' . Html::encode($href) . '">'
        . Html::encode($label . $query->storeSort->markerFor($field)) . '</a></th>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Chat Reports</h1>
        <p class="page-header__subtitle">
            Agent chat activity, answer quality and ratings for the selected period. Read-only —
            nothing here changes a conversation, a rating or a document.
        </p>
    </div>
</div>

<?php if ($range->wasAdjusted): ?>
    <div class="alert alert--warning">
        <span class="alert__icon" aria-hidden="true">⚠</span>
        The requested dates were not usable (or wider than <?= ReportDateRange::MAX_DAYS ?> days), so the
        report is showing <?= Html::encode($range->from) ?> to <?= Html::encode($range->to) ?>.
    </div>
<?php endif; ?>

<?php if ($detail !== null): ?>
    <?php /* Reached by opening a "View" link without JavaScript; with it, this same record is a dialog. */ ?>
    <section class="card report__card" id="report-detail">
        <div class="report__section-head">
            <h2 class="card__title">Question detail</h2>
            <a class="btn btn--ghost btn--sm" href="<?= Html::encode($reportUrl([])) ?>">← Back to report</a>
        </div>
        <?php /* Only the rating: the rest of the record is on the row this was opened from. */ ?>
        <dl class="report-modal__facts">
            <dt>Rating</dt>
            <dd>
                <?php if ($detail->score !== null): ?><?= Html::encode(ScoreDisplay::label($detail->score)) ?>
                <?php elseif ($detail->dismissed): ?>Declined
                <?php elseif ($detail->isAnswered()): ?>Unrated
                <?php else: ?>—<?php endif; ?>
            </dd>
        </dl>

        <h3 class="report-modal__section">Question</h3>
        <pre class="source-modal__content"><?= Html::encode($detail->question) ?></pre>

        <h3 class="report-modal__section">Answer</h3>
        <pre class="source-modal__content"><?= $detail->answer === null
            ? 'No active answer for this question.'
            : Html::encode($detail->answer) ?></pre>

        <?php if ($detail->comment !== null): ?>
            <h3 class="report-modal__section">Feedback comment</h3>
            <pre class="source-modal__content"><?= Html::encode($detail->comment) ?></pre>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="card report__filters">
    <nav class="filter-bar report__presets" aria-label="Quick date ranges">
        <?php foreach ($presets as $preset): ?>
            <a class="filter-chip<?= $preset['active'] ? ' filter-chip--active' : '' ?>"
               href="<?= Html::encode($reportUrl([
                   'from' => $preset['from'],
                   'to' => $preset['to'],
                   'agent_page' => 1,
                   'store_page' => 1,
                   'qa_page' => 1,
               ])) ?>"
               title="<?= Html::encode($preset['from'] . ' to ' . $preset['to']) ?>"
               <?= $preset['active'] ? 'aria-current="true"' : '' ?>><?= Html::encode($preset['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <form method="get" action="<?= Html::encode($base) ?>">
        <div class="report__filter-grid">
            <div class="field">
                <label class="field__label" for="rf-from">From</label>
                <input class="field__control" type="date" id="rf-from" name="from" value="<?= Html::encode($range->from) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="rf-to">To</label>
                <input class="field__control" type="date" id="rf-to" name="to" value="<?= Html::encode($range->to) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="rf-agent">Agent</label>
                <select class="field__control" id="rf-agent" name="agent">
                    <option value="">All agents</option>
                    <?php foreach ($agentOptions as $option): ?>
                        <option value="<?= $option['id'] ?>"<?= $query->agentAdminId === $option['id'] ? ' selected' : '' ?>>
                            <?= Html::encode($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="rf-store">Store</label>
                <select class="field__control" id="rf-store" name="store">
                    <option value="">All stores</option>
                    <?php foreach ($storeOptions as $option): ?>
                        <option value="<?= $option['id'] ?>"<?= $query->knowledgeBaseId === $option['id'] ? ' selected' : '' ?>>
                            <?= Html::encode($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="rf-type">Chat type</label>
                <select class="field__control" id="rf-type" name="type">
                    <?php foreach (ChatTypeFilter::cases() as $case): ?>
                        <option value="<?= Html::encode($case->value) ?>"<?= $query->chatType === $case ? ' selected' : '' ?>><?= Html::encode($case->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="rf-rating">Rating</label>
                <select class="field__control" id="rf-rating" name="rating">
                    <?php foreach (RatingFilter::cases() as $case): ?>
                        <option value="<?= Html::encode($case->value) ?>"<?= $query->rating === $case ? ' selected' : '' ?>><?= Html::encode($case->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="rf-feedback">Feedback</label>
                <select class="field__control" id="rf-feedback" name="feedback">
                    <?php foreach (FeedbackFilter::cases() as $case): ?>
                        <option value="<?= Html::encode($case->value) ?>"<?= $query->feedback === $case ? ' selected' : '' ?>><?= Html::encode($case->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="rf-status">Answer status</label>
                <select class="field__control" id="rf-status" name="status">
                    <?php foreach (AnswerStatusFilter::cases() as $case): ?>
                        <option value="<?= Html::encode($case->value) ?>"<?= $query->status === $case ? ' selected' : '' ?>><?= Html::encode($case->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field report__actions">
                <button class="btn btn--primary" type="submit">Apply filters</button>
                <a class="btn btn--ghost" href="<?= Html::encode($base) ?>">Reset filters</a>
            </div>
        </div>
    </form>
</div>

<section class="grid grid--report-stats">
    <div class="stat">
        <div class="stat__value"><?= $summary->activeAgents ?></div>
        <div class="stat__label">Active agents</div>
    </div>
    <div class="stat">
        <div class="stat__value"><?= $summary->questions ?></div>
        <div class="stat__label">Questions</div>
        <?php if ($summary->unansweredQuestions > 0): ?>
            <div class="stat__hint"><?= $summary->unansweredQuestions ?> with no active answer</div>
        <?php endif; ?>
    </div>
    <div class="stat">
        <div class="stat__value"><?= $summary->answers ?></div>
        <div class="stat__label">Answers</div>
        <div class="stat__hint">Superseded answers excluded</div>
    </div>
    <div class="stat">
        <div class="stat__value"><?= $rating($summary->averageRating) ?></div>
        <div class="stat__label">Average rating</div>
        <div class="stat__hint">Scores only · dismissals are not zeros</div>
    </div>
    <?php $coverage = $summary->ratingCoveragePercent(); ?>
    <div class="stat">
        <div class="stat__value"><?= $coverage === null ? '—' : $coverage . '%' ?></div>
        <div class="stat__label">Rating coverage</div>
        <div class="stat__hint"><?= $summary->ratedAnswers ?> rated · <?= $summary->unratedAnswers ?> unrated</div>
    </div>
    <div class="stat">
        <div class="stat__value"><?= $summary->lowRatings ?></div>
        <div class="stat__label">Low ratings (1–3)</div>
        <?php if ($summary->comments > 0): ?>
            <div class="stat__hint"><?= $summary->comments ?> with a comment</div>
        <?php endif; ?>
    </div>
    <div class="stat">
        <div class="stat__value"><?= $summary->storeQuestions ?></div>
        <div class="stat__label">Store knowledge questions</div>
    </div>
    <div class="stat">
        <div class="stat__value"><?= $summary->ruleQuestions ?></div>
        <div class="stat__label">Rule chat questions</div>
    </div>
    <?php $fallbackShare = $summary->fallbackPercent(); ?>
    <div class="stat">
        <div class="stat__value"><?= $summary->fallbackAnswers ?></div>
        <div class="stat__label">Fallback answers</div>
        <?php if ($fallbackShare !== null): ?>
            <div class="stat__hint"><?= $fallbackShare ?>% of answers</div>
        <?php endif; ?>
    </div>
    <div class="stat">
        <div class="stat__value"><?= Html::encode(ScoreDisplay::duration($summary->chatSeconds)) ?></div>
        <div class="stat__label">Estimated chat time</div>
        <div class="stat__hint"><?= $summary->sessions ?> sessions · avg response <?= Html::encode(ScoreDisplay::responseTime($summary->averageResponseSeconds)) ?></div>
    </div>
</section>

<p class="util-muted report__note">
    Chat time is estimated from message activity. A new session is assumed after
    <?= ChatReportQuery::SESSION_GAP_MINUTES ?> minutes of inactivity, and a session is measured from its
    first message to its last — time spent reading before or after that is not observable from the data and
    is not counted. This is activity span, not time on page. Every number below is a link: select one to see
    the records behind it.
</p>

<section class="card report__card">
    <div class="report__section-head">
        <h2 class="card__title">Agent usage <span class="util-muted">(<?= $agents->total ?>)</span></h2>
        <span class="util-muted">Page <?= $agentPage ?> of <?= $agents->pageCount() ?></span>
    </div>

    <?php if ($agents->items === []): ?>
        <p class="util-muted">No agent asked a question in this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= $agentHeader('agent', 'Agent') ?>
                        <?= $agentHeader('questions', 'Questions') ?>
                        <th>Knowledge</th>
                        <th>Rules</th>
                        <th>Answers</th>
                        <th>Rated</th>
                        <?= $agentHeader('avg_rating', 'Avg rating') ?>
                        <?= $agentHeader('low_ratings', 'Low') ?>
                        <th>Comments</th>
                        <?= $agentHeader('chat_time', 'Est. time') ?>
                        <th>Avg response</th>
                        <?= $agentHeader('last_activity', 'Last activity') ?>
                        <th>Last login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents->items as $agent): ?>
                        <?php
                        $who = ['agent' => $agent->agentAdminId];
                        $context = $agent->agentLabel() . ' · ' . $range->from . ' to ' . $range->to;
                        ?>
                        <tr>
                            <td>
                                <strong><?= Html::encode($agent->agentLabel()) ?></strong>
                                <?php if ($agent->agentUsername !== null): ?>
                                    <div class="util-muted"><?= Html::encode($agent->agentUsername) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $metric($agent->questions, $who, 'Questions', $context) ?></td>
                            <td><?= $metric($agent->storeQuestions, $who + ['type' => ChatTypeFilter::Store->value], 'Store knowledge questions', $context) ?></td>
                            <td><?= $metric($agent->ruleQuestions, $who + ['type' => ChatTypeFilter::Rule->value], 'Rule chat questions', $context) ?></td>
                            <td><?= $metric($agent->answers, $who + ['status' => AnswerStatusFilter::Answered->value], 'Answered questions', $context) ?></td>
                            <td><?= $metric($agent->ratedAnswers, $who + ['rating' => RatingFilter::Rated->value], 'Rated answers', $context) ?></td>
                            <td><?= $metric($rating($agent->averageRating), $who + ['rating' => RatingFilter::Rated->value], 'Answers behind this average', $context) ?></td>
                            <td><?= $metric($agent->lowRatings, $who + ['rating' => RatingFilter::Low->value], 'Low ratings (1–3)', $context) ?></td>
                            <td><?= $metric($agent->comments, $who + ['feedback' => FeedbackFilter::WithComment->value], 'Answers with a comment', $context) ?></td>
                            <td><?= Html::encode(ScoreDisplay::duration($agent->chatSeconds)) ?></td>
                            <td><?= Html::encode(ScoreDisplay::responseTime($agent->averageResponseSeconds)) ?></td>
                            <td class="util-muted"><?= Html::encode($localTime($agent->lastActivityAt)) ?></td>
                            <td class="util-muted"><?= Html::encode($localTime($agent->lastLoginAt)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $agentPage,
            'pageCount' => $agents->pageCount(),
            'pageUrl' => static fn(int $p): string => $reportUrl(['agent_page' => $p]),
        ]) ?>
    <?php endif; ?>
</section>

<section class="card report__card">
    <div class="report__section-head">
        <h2 class="card__title">Store usage <span class="util-muted">(<?= $stores->total ?>)</span></h2>
        <span class="util-muted">Page <?= $storePage ?> of <?= $stores->pageCount() ?></span>
    </div>
    <p class="util-muted">
        A store with many fallback answers and a low average rating is one whose knowledge needs work.
    </p>

    <?php if ($stores->items === []): ?>
        <p class="util-muted">No questions were asked in this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= $storeHeader('store', 'Store') ?>
                        <th>Type</th>
                        <?= $storeHeader('questions', 'Questions') ?>
                        <?= $storeHeader('agents', 'Agents') ?>
                        <th>Rated</th>
                        <?= $storeHeader('avg_rating', 'Avg rating') ?>
                        <?= $storeHeader('low_ratings', 'Low') ?>
                        <?= $storeHeader('fallback', 'Fallback') ?>
                        <?= $storeHeader('last_activity', 'Last activity') ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stores->items as $store): ?>
                        <?php
                        $where = ['store' => $store->knowledgeBaseId];
                        $label = $store->isRuleChat() ? 'Global Rules' : $store->storeName;
                        $context = $label . ' · ' . $range->from . ' to ' . $range->to;
                        ?>
                        <tr>
                            <td><?= Html::encode($label) ?></td>
                            <td><span class="badge badge--<?= $store->isRuleChat() ? 'info' : 'muted' ?>"><?= $store->isRuleChat() ? 'Rule Chat' : 'Knowledge' ?></span></td>
                            <td><?= $metric($store->questions, $where, 'Questions', $context) ?></td>
                            <td><?= $metric($store->uniqueAgents, $where, 'Agents who asked here', $context) ?></td>
                            <td><?= $metric($store->ratedAnswers, $where + ['rating' => RatingFilter::Rated->value], 'Rated answers', $context) ?></td>
                            <td><?= $metric($rating($store->averageRating), $where + ['rating' => RatingFilter::Rated->value], 'Answers behind this average', $context) ?></td>
                            <td><?= $metric($store->lowRatings, $where + ['rating' => RatingFilter::Low->value], 'Low ratings (1–3)', $context) ?></td>
                            <td><?= $metric($store->fallbackAnswers, $where + ['status' => AnswerStatusFilter::Fallback->value], 'Fallback answers', $context) ?></td>
                            <td class="util-muted"><?= Html::encode($localTime($store->lastActivityAt)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $storePage,
            'pageCount' => $stores->pageCount(),
            'pageUrl' => static fn(int $p): string => $reportUrl(['store_page' => $p]),
        ]) ?>
    <?php endif; ?>
</section>

<section class="card report__card">
    <div class="report__section-head">
        <h2 class="card__title">
            Questions &amp; answers <span class="util-muted">(<?= $result->total ?>)</span>
        </h2>
        <span class="util-muted">Page <?= $qaPage ?> of <?= $result->pageCount() ?></span>
    </div>

    <form class="report__search" method="get" action="<?= Html::encode($base) ?>" role="search">
        <input type="hidden" name="from" value="<?= Html::encode($range->from) ?>">
        <input type="hidden" name="to" value="<?= Html::encode($range->to) ?>">
        <?php if ($query->chatType !== ChatTypeFilter::All): ?><input type="hidden" name="type" value="<?= Html::encode($query->chatType->value) ?>"><?php endif; ?>
        <?php if ($query->rating !== RatingFilter::All): ?><input type="hidden" name="rating" value="<?= Html::encode($query->rating->value) ?>"><?php endif; ?>
        <?php if ($query->feedback !== FeedbackFilter::All): ?><input type="hidden" name="feedback" value="<?= Html::encode($query->feedback->value) ?>"><?php endif; ?>
        <?php if ($query->status !== AnswerStatusFilter::All): ?><input type="hidden" name="status" value="<?= Html::encode($query->status->value) ?>"><?php endif; ?>
        <?php if ($query->agentAdminId !== null): ?><input type="hidden" name="agent" value="<?= $query->agentAdminId ?>"><?php endif; ?>
        <?php if ($query->knowledgeBaseId !== null): ?><input type="hidden" name="store" value="<?= $query->knowledgeBaseId ?>"><?php endif; ?>
        <label class="util-visually-hidden" for="rf-q">Search questions and answers</label>
        <input class="field__control" type="search" id="rf-q" name="q" value="<?= Html::encode($query->search) ?>"
               placeholder="Search question or answer text…">
        <button class="btn btn--secondary" type="submit">Search</button>
        <?php if ($query->search !== ''): ?>
            <a class="btn btn--ghost" href="<?= Html::encode($reportUrl(['q' => '', 'qa_page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <p class="util-muted report__note report__note--tight">
        Searches the full question and answer text, which the View dialog shows in full. This filters the
        table below only — the summary cards and the usage tables always cover the whole selected period.
    </p>

    <?php if ($result->items === []): ?>
        <p class="util-muted">
            <?= $query->hasActiveFilters()
                ? 'No questions match these filters.'
                : 'No agent questions were asked in this period.' ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asked</th>
                        <th>Agent</th>
                        <th>Type</th>
                        <th>Store</th>
                        <th>Rating</th>
                        <th>Comment</th>
                        <th>Status</th>
                        <th>Response</th>
                        <th><span class="util-visually-hidden">Detail</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result->items as $row): ?>
                        <?php /** @var ChatReportRow $row */ ?>
                        <tr>
                            <td class="util-muted"><?= Html::encode($localTime($row->askedAt)) ?></td>
                            <td><?= Html::encode($row->agentLabel()) ?></td>
                            <td><?= $row->chatType === ChatTypeFilter::Rule ? 'Rule Chat' : 'Knowledge' ?></td>
                            <td><?= $row->chatType === ChatTypeFilter::Rule ? '—' : $dash($row->storeName) ?></td>
                            <td>
                                <?php if ($row->score !== null): ?>
                                    <span class="report__score" data-score-band="<?= Html::encode(ScoreDisplay::bandSlug($row->score)) ?>">
                                        <?= Html::encode(ScoreDisplay::label($row->score)) ?>
                                    </span>
                                <?php elseif ($row->dismissed): ?>
                                    <span class="util-muted">Declined</span>
                                <?php elseif ($row->isAnswered()): ?>
                                    <span class="util-muted">Unrated</span>
                                <?php else: ?>
                                    <span class="util-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $row->comment !== null ? 'Yes' : '<span class="util-muted">—</span>' ?></td>
                            <td>
                                <?php if (!$row->isAnswered()): ?>
                                    <span class="badge badge--warning">No active answer</span>
                                <?php elseif ($row->isGrounded): ?>
                                    <span class="badge badge--success">Grounded</span>
                                <?php else: ?>
                                    <span class="badge badge--muted">Fallback</span>
                                <?php endif; ?>
                            </td>
                            <td class="util-muted"><?= $row->responseSeconds === null ? '—' : Html::encode(ScoreDisplay::duration($row->responseSeconds)) ?></td>
                            <td>
                                <a class="report__metric"
                                   href="<?= Html::encode($reportUrl(['question' => $row->questionId])) ?>"
                                   data-report-single="<?= Html::encode($stateUrl([], 'detail')) ?>"
                                   data-report-question="<?= $row->questionId ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $qaPage,
            'pageCount' => $result->pageCount(),
            'pageUrl' => static fn(int $p): string => $reportUrl(['qa_page' => $p]),
        ]) ?>
    <?php endif; ?>
</section>

<?= $this->render(ReportViews::modal()) ?>
