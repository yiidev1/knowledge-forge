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
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var ChatReportQuery $query
 * @var ReportDateRange $range
 * @var ChatReportSummary $summary
 * @var list<AgentUsageRow> $agents
 * @var list<StoreUsageRow> $stores
 * @var ChatReportResult $result
 * @var int $page
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

/** Rebuilds the URL with some state replaced, dropping anything at its default so links stay readable. */
$reportUrl = static function (array $overrides) use ($base, $query, $range, $page): string {
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
        'page' => (string) $page,
    ];
    foreach ($overrides as $key => $value) {
        $state[$key] = (string) $value;
    }

    $params = ['from' => $state['from'], 'to' => $state['to']];
    if ($state['type'] !== ChatTypeFilter::All->value) {
        $params['type'] = $state['type'];
    }
    if ($state['rating'] !== RatingFilter::All->value) {
        $params['rating'] = $state['rating'];
    }
    if ($state['feedback'] !== FeedbackFilter::All->value) {
        $params['feedback'] = $state['feedback'];
    }
    if ($state['status'] !== AnswerStatusFilter::All->value) {
        $params['status'] = $state['status'];
    }
    if ($state['agent'] !== '') {
        $params['agent'] = $state['agent'];
    }
    if ($state['store'] !== '') {
        $params['store'] = $state['store'];
    }
    if ($state['q'] !== '') {
        $params['q'] = $state['q'];
    }
    if ($state['sort'] !== 'questions' || $state['dir'] !== 'desc') {
        $params['sort'] = $state['sort'];
        $params['dir'] = $state['dir'];
    }
    if ((int) $state['page'] > 1) {
        $params['page'] = $state['page'];
    }

    return $base . '?' . http_build_query($params);
};

$dash = static fn(?string $v): string => $v === null || $v === '' ? '—' : Html::encode($v);
$localTime = static fn(?DateTimeImmutable $d): string => $d === null ? '—' : $appTimeZone->format($d, 'M j, Y g:i A');
$rating = static fn(?float $v): string => $v === null ? '—' : sprintf('%.1f', $v);

/** Long free text: a short preview that expands in place. Everything is escaped — never innerHTML. */
$longText = static function (?string $text, int $preview = 70): string {
    if ($text === null || trim($text) === '') {
        return '<span class="util-muted">—</span>';
    }
    $clean = trim($text);
    if (mb_strlen($clean) <= $preview) {
        return Html::encode($clean);
    }

    return '<details><summary>' . Html::encode(mb_substr($clean, 0, $preview)) . '…</summary>'
        . '<div class="report__full">' . Html::encode($clean) . '</div></details>';
};

$sortableHeader = static function (string $field, string $label) use ($query, $reportUrl): string {
    $href = $reportUrl(['sort' => $field, 'dir' => $query->agentSort->nextDirectionFor($field), 'page' => 1]);

    return '<th aria-sort="' . Html::encode($query->agentSort->ariaFor($field)) . '">'
        . '<a href="' . Html::encode($href) . '">'
        . Html::encode($label . $query->agentSort->markerFor($field)) . '</a></th>';
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

<div class="card report__filters">
    <nav class="filter-bar report__presets" aria-label="Quick date ranges">
        <?php foreach ($presets as $preset): ?>
            <a class="filter-chip<?= $preset['active'] ? ' filter-chip--active' : '' ?>"
               href="<?= Html::encode($reportUrl(['from' => $preset['from'], 'to' => $preset['to'], 'page' => 1])) ?>"
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
    <div class="stat">
        <?php $coverage = $summary->ratingCoveragePercent(); ?>
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
    <div class="stat">
        <div class="stat__value"><?= $summary->fallbackAnswers ?></div>
        <div class="stat__label">Fallback answers</div>
        <?php $fallbackShare = $summary->fallbackPercent(); ?>
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
    is not counted. This is activity span, not time on page.
</p>

<section class="card report__card">
    <h2 class="card__title">Agent usage</h2>

    <?php if ($agents === []): ?>
        <p class="util-muted">No agent asked a question in this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= $sortableHeader('agent', 'Agent') ?>
                        <?= $sortableHeader('questions', 'Questions') ?>
                        <th>Knowledge</th>
                        <th>Rules</th>
                        <th>Answers</th>
                        <th>Rated</th>
                        <?= $sortableHeader('avg_rating', 'Avg rating') ?>
                        <?= $sortableHeader('low_ratings', 'Low') ?>
                        <th>Comments</th>
                        <th>Sessions</th>
                        <?= $sortableHeader('chat_time', 'Est. time') ?>
                        <th>Avg session</th>
                        <?= $sortableHeader('last_activity', 'Last activity') ?>
                        <th>Last login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $agent): ?>
                        <tr>
                            <td>
                                <strong><?= Html::encode($agent->agentLabel()) ?></strong>
                                <?php if ($agent->agentUsername !== null): ?>
                                    <div class="util-muted"><?= Html::encode($agent->agentUsername) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $agent->questions ?></td>
                            <td><?= $agent->storeQuestions ?></td>
                            <td><?= $agent->ruleQuestions ?></td>
                            <td><?= $agent->answers ?></td>
                            <td><?= $agent->ratedAnswers ?></td>
                            <td><?= $rating($agent->averageRating) ?></td>
                            <td><?= $agent->lowRatings ?></td>
                            <td><?= $agent->comments ?></td>
                            <td><?= $agent->sessions ?></td>
                            <td><?= Html::encode(ScoreDisplay::duration($agent->chatSeconds)) ?></td>
                            <td><?= Html::encode(ScoreDisplay::duration($agent->averageSessionSeconds())) ?></td>
                            <td class="util-muted"><?= Html::encode($localTime($agent->lastActivityAt)) ?></td>
                            <td class="util-muted"><?= Html::encode($localTime($agent->lastLoginAt)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card report__card">
    <h2 class="card__title">Store usage</h2>
    <p class="util-muted">
        A store with many fallback answers and a low average rating is one whose knowledge needs work.
    </p>

    <?php if ($stores === []): ?>
        <p class="util-muted">No questions were asked in this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Type</th>
                        <th>Questions</th>
                        <th>Agents</th>
                        <th>Rated</th>
                        <th>Avg rating</th>
                        <th>Low</th>
                        <th>Fallback</th>
                        <th>Last activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stores as $store): ?>
                        <tr>
                            <td><?= $store->isRuleChat() ? 'Global Rules' : Html::encode($store->storeName) ?></td>
                            <td><span class="badge badge--<?= $store->isRuleChat() ? 'info' : 'muted' ?>"><?= $store->isRuleChat() ? 'Rule Chat' : 'Knowledge' ?></span></td>
                            <td><?= $store->questions ?></td>
                            <td><?= $store->uniqueAgents ?></td>
                            <td><?= $store->ratedAnswers ?></td>
                            <td><?= $rating($store->averageRating) ?></td>
                            <td><?= $store->lowRatings ?></td>
                            <td><?= $store->fallbackAnswers ?></td>
                            <td class="util-muted"><?= Html::encode($localTime($store->lastActivityAt)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card report__card">
    <div class="report__section-head">
        <h2 class="card__title">
            Questions &amp; answers <span class="util-muted">(<?= $result->total ?>)</span>
        </h2>
        <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
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
            <a class="btn btn--ghost" href="<?= Html::encode($reportUrl(['q' => '', 'page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <p class="util-muted report__note report__note--tight">
        This search filters the table below only. The summary cards and the usage tables above always cover
        the whole selected period.
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
                        <th>Question</th>
                        <th>Answer</th>
                        <th>Rating</th>
                        <th>Status</th>
                        <th>Response</th>
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
                                <?= $longText($row->question) ?>
                                <?php if ($row->questionEdited): ?>
                                    <div class="util-muted">· Edited</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $longText($row->answer) ?>
                                <?php if ($row->citationCount > 0): ?>
                                    <div class="util-muted"><?= $row->citationCount ?> source<?= $row->citationCount === 1 ? '' : 's' ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row->score !== null): ?>
                                    <span class="report__score" data-score-band="<?= Html::encode(ScoreDisplay::bandSlug($row->score)) ?>">
                                        <?= Html::encode(ScoreDisplay::label($row->score)) ?>
                                    </span>
                                    <?php if ($row->comment !== null): ?>
                                        <div><?= $longText($row->comment, 50) ?></div>
                                    <?php endif; ?>
                                <?php elseif ($row->dismissed): ?>
                                    <span class="util-muted">Declined</span>
                                <?php elseif ($row->isAnswered()): ?>
                                    <span class="util-muted">Unrated</span>
                                <?php else: ?>
                                    <span class="util-muted">—</span>
                                <?php endif; ?>
                            </td>
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $page,
            'pageCount' => $result->pageCount(),
            'pageUrl' => static fn(int $p): string => $reportUrl(['page' => $p]),
        ]) ?>
    <?php endif; ?>
</section>
