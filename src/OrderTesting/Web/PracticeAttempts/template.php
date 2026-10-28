<?php

declare(strict_types=1);

use App\OrderTesting\Application\Practice\PractitionerScores;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\Practice\PracticeResultsQuery;
use App\OrderTesting\Domain\Practice\PractitionerSummary;
use App\OrderTesting\Web\ComparisonLinks;
use App\OrderTesting\Web\PracticeAttemptsAsset;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Practice Results: who practised one source order, as a table rather than a wall of cards.
 *
 * ## Why a table
 *
 * One card per try, grouped under one heading per practitioner, is readable at four results and
 * unusable at four hundred — a hundred practitioners with three tries each is a page nobody scrolls
 * to the bottom of, and every card on it costs a document parsed and a comparison scored.
 *
 * So each practitioner is one row: how many results they produced, every try's score — each one a
 * link to that try's comparison — and when they last practised. The full history, paged, is one click
 * away in a drawer, fetched only for the practitioner somebody actually opened.
 *
 * ## The topic is context, not a control
 *
 * `practice_order_topics` is unique on `(store, source order)`, so every result on this page carries
 * the same topic. A filter offering it would have one option that excluded nothing, so it is printed
 * beside the order number as the fact it is.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Yiisoft\Yii\View\Renderer\Csrf $csrf
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var AppTimeZone $appTimeZone
 * @var int $sourceId
 * @var string $storeName
 * @var int $sourceOrderId
 * @var PracticeResultsQuery $query the search and sort in force
 * @var list<PractitionerSummary> $practitioners this page's rows, in display order
 * @var array<string, PractitionerScores> $scores keyed by practitioner key
 * @var int $page
 * @var int $pages
 * @var int $perPage
 * @var int $totalPractitioners
 * @var int $totalResults
 * @var int $unmatchedCount
 * @var list<DemoOrder> $unmatched
 * @var array<int, App\OrderTesting\Domain\Comparison\ComparisonScore> $unmatchedScores
 * @var ?string $topic the source order's current topic, or null
 * @var bool $canRetry
 * @var bool $readerHasPractised
 * @var bool $sourceOrderIsMirrored
 * @var array<string, string> $routes
 */

$assetManager->register(PracticeAttemptsAsset::class);

$this->setTitle('Practice results for #' . $sourceOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => $routes['pageLabel'], 'route' => $routes['page']],
    ['label' => $storeName, 'route' => $routes['store'], 'arguments' => ['sourceId' => $sourceId]],
    ['label' => 'Practice results'],
]);

$storeUrl = $urlGenerator->generate($routes['store'], ['sourceId' => $sourceId]);
$selfUrl = $urlGenerator->generate(
    $routes['self'],
    ['sourceId' => $sourceId, 'sourceOrderId' => $sourceOrderId],
);
$allDemoUrl = $urlGenerator->generate(
    $routes['allDemoOrders'],
    ['sourceId' => $sourceId, 'sourceOrderId' => $sourceOrderId],
);

// Every comparison link on this page — scores, row actions and unmatched results — comes from here.
$compare = new ComparisonLinks($urlGenerator, $routes['demoCompare'], $sourceId, $sourceOrderId);

/**
 * A score as a percentage, or a dash where there is none.
 *
 * `overall` is null when the comparison could not be made — the source order is not mirrored here,
 * or its items were never fetched. **Never rendered as 0%**: a zero is a real score somebody earned
 * badly, and showing it for a comparison nobody performed would rank a practitioner below one who
 * actually did poorly.
 */
$percent = static function (?App\OrderTesting\Domain\Comparison\ComparisonScore $score): string {
    if ($score === null || !$score->isAvailable()) {
        return '—';
    }

    return (string) $score->displayPercent() . '%';
};

/** The band a score sits in, for the one piece of colour this table uses. */
$band = static function (?App\OrderTesting\Domain\Comparison\ComparisonScore $score): string {
    if ($score === null || !$score->isAvailable()) {
        return 'none';
    }

    $value = (int) $score->displayPercent();

    return match (true) {
        $value >= 90 => 'excellent',
        $value >= 75 => 'good',
        $value >= 50 => 'fair',
        default => 'poor',
    };
};

/** This page's address under a different page or sort, keeping everything else in force. */
$pageUrl = static function (array $extra) use ($urlGenerator, $routes, $sourceId, $sourceOrderId, $query): string {
    return $urlGenerator->generate(
        $routes['self'],
        ['sourceId' => $sourceId, 'sourceOrderId' => $sourceOrderId],
        $query->toParams() + $extra,
    );
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Practice Results</h1>
        <p class="page-header__subtitle">
            <?= Html::encode($storeName) ?> &middot; Store #<?= Html::encode((string) $sourceId) ?>
            &mdash; source order #<?= Html::encode((string) $sourceOrderId) ?>.
            <?php
            // The topic, as a fact about the order. See the docblock: it is not a filter because on
            // this page it could not narrow anything.
?>
            <?php if ($topic !== null): ?>
                <span class="pr-topic">Topic: <?= Html::encode($topic) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-header__actions">
        <?php if ($canRetry): ?>
            <div class="pa-retry-wrap">
                <?php
                // Unchanged from the page this replaces: the same tracked endpoint, the same
                // attempt, the same new-tab behaviour. Only the table around it changed.
            ?>
                <form class="pa-retry" method="post" data-pa-retry
                      target="_blank" rel="noopener noreferrer"
                      action="<?= Html::encode($urlGenerator->generate($routes['demoUrl'], ['sourceId' => $sourceId])) ?>">
                    <?= $csrf->hiddenInput() ?>
                    <input type="hidden" name="source_order_id" value="<?= Html::encode((string) $sourceOrderId) ?>">
                    <button class="btn btn--primary" type="submit"><?=
                        $readerHasPractised ? 'Retry Practice' : 'Start Practice' ?></button>
                </form>
                <p class="pa-retry__error" data-pa-retry-error hidden></p>
            </div>
        <?php endif; ?>
        <a class="btn btn--secondary" href="<?= Html::encode($allDemoUrl) ?>">All Demo Orders</a>
        <a class="btn btn--ghost" href="<?= Html::encode($storeUrl) ?>">Back to Store</a>
    </div>
</div>

<?php if (!$sourceOrderIsMirrored): ?>
    <p class="pa-warning">
        This source order is not mirrored here, so comparisons have nothing to compare against.
        Sync this store's orders to fill the original side.
    </p>
<?php endif; ?>

<?php
// Three counts, and they are three different things on purpose: people, results, and the results
// nobody can be shown to have produced. Each is a plain indexed count — no document is read to
// produce any of them, which is why they are exact even when the scores on the page are budgeted.
?>
<div class="pr-summary">
    <div class="pr-summary__item">
        <span class="pr-summary__value"><?= Html::encode((string) $totalPractitioners) ?></span>
        <span class="pr-summary__label">Practitioners</span>
    </div>
    <div class="pr-summary__item">
        <span class="pr-summary__value"><?= Html::encode((string) $totalResults) ?></span>
        <span class="pr-summary__label">Imported results</span>
    </div>
    <div class="pr-summary__item<?= $unmatchedCount > 0 ? ' pr-summary__item--flagged' : '' ?>">
        <span class="pr-summary__value"><?= Html::encode((string) $unmatchedCount) ?></span>
        <span class="pr-summary__label">Unmatched</span>
    </div>
</div>

<div class="card a2t-wide">
    <h2 class="card__title">Practitioners</h2>

    <?php
    // A GET form: the search and the sort live in the address, which is what makes them survive a
    // reload, a bookmark, the pager and the browser's Back button without a line of script.
?>
    <form class="pr-toolbar" method="get" action="<?= Html::encode($selfUrl) ?>">
        <label class="util-visually-hidden" for="pr-agent">Search practitioners</label>
        <input class="field__control pr-toolbar__search" type="search" id="pr-agent"
               name="<?= PracticeResultsQuery::SEARCH ?>" placeholder="Search by name"
               value="<?= Html::encode($query->search ?? '') ?>">

        <label class="util-visually-hidden" for="pr-sort">Sort</label>
        <select class="field__control pr-toolbar__sort" id="pr-sort" name="<?= PracticeResultsQuery::SORT ?>">
            <?php foreach ([
                'recent' => 'Latest practice first',
                'name' => 'Name',
                'tries' => 'Most tries first',
            ] as $value => $label): ?>
                <option value="<?= Html::encode($value) ?>"<?=
                    $query->sort === $value ? ' selected' : '' ?>><?= Html::encode($label) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn--secondary btn--sm" type="submit">Apply</button>
        <?php if ($query->isFiltered()): ?>
            <a class="btn btn--sm" href="<?= Html::encode($selfUrl) ?>">Clear</a>
        <?php endif; ?>

        <span class="pr-toolbar__count">
            <?= Html::encode((string) $totalPractitioners) ?>
            practitioner<?= $totalPractitioners === 1 ? '' : 's' ?>
        </span>
    </form>

    <?php if ($practitioners === []): ?>
        <div class="a2t-empty-state">
            <?php if ($query->isFiltered()): ?>
                <p>No practitioner matches that search.</p>
                <a class="btn" href="<?= Html::encode($selfUrl) ?>">Show all practitioners</a>
            <?php else: ?>
                <?php
                // Not "no attempts": somebody may well have pressed Practice Order. What there is
                // none of is imported results, which is a different and more useful thing to say.
                ?>
                <p>No completed practice results yet.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="a2t-table-scroll">
            <table class="table pr-table pr-table--practitioners">
                <colgroup>
                    <col class="pr-col-agent">
                    <col class="pr-col-num">
                    <col class="pr-col-scores">
                    <col class="pr-col-when">
                    <col class="pr-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Practitioner</th>
                        <th class="pr-num">Tries</th>
                        <th>Try scores</th>
                        <th>Last practice</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($practitioners as $practitioner): ?>
                        <?php
                        $rowScores = $scores[$practitioner->key()] ?? null;
                        $triesUrl = $urlGenerator->generate($routes['tries'], [
                            'sourceId' => $sourceId,
                            'sourceOrderId' => $sourceOrderId,
                            'realm' => $practitioner->realm->value,
                            'actorId' => $practitioner->actorId,
                        ]);
                        ?>
                        <tr>
                            <td data-ot-label="Practitioner">
                                <span class="pr-agent"><?= Html::encode($practitioner->displayName()) ?></span>
                                <span class="pr-agent__realm"><?= Html::encode($practitioner->realm->label()) ?></span>
                            </td>
                            <td data-ot-label="Tries" class="pr-num"><?=
                                Html::encode((string) $practitioner->completedTries) ?></td>
                            <td data-ot-label="Try scores">
                                <?php
                                /*
                                 * Every try's score, oldest first, each opening that try's comparison.
                                 *
                                 * The same scores the row always computed, kept rather than reduced to
                                 * one. When the page could not afford a practitioner's whole history,
                                 * the earlier tries it did not score are counted and one click away in
                                 * the drawer — never silently left off, so a short list cannot pass for
                                 * the whole of somebody's record.
                                 */
                                $earlier = $rowScores === null ? 0 : $rowScores->earlierTriesNotListed($practitioner->completedTries);
                        $latestTry = $rowScores?->latestTry();
                        ?>
                                <div class="pr-chips">
                                    <?php if ($earlier > 0): ?>
                                        <a class="pr-chips__more" href="<?= Html::encode($triesUrl) ?>"
                                           data-pr-tries="<?= Html::encode($triesUrl) ?>"
                                           data-pr-agent="<?= Html::encode($practitioner->displayName()) ?>"
                                           title="Too many to score on this page. Open their tries to see each one.">+<?=
                                    Html::encode((string) $earlier) ?> earlier</a>
                                    <?php endif; ?>
                                    <?php foreach ($rowScores?->tries ?? [] as $try): ?>
                                        <?= $compare->chip(
                                            $try->demo->demoOrderId,
                                            $try->number,
                                            $percent($try->score),
                                            $band($try->score),
                                            $try === $latestTry,
                                            numbered: true,
                                        ) ?>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td data-ot-label="Last practice">
                                <?= $practitioner->lastPractisedAt === null
                                    ? '<span class="util-muted">&mdash;</span>'
                                    : Html::encode($appTimeZone->format($practitioner->lastPractisedAt, 'M j, Y')) ?>
                            </td>
                            <td data-ot-label="Actions" class="pr-actions">
                                <?php
                                // Both actions in one cell, side by side. View Comparison opens their
                                // latest try — the chip marked Latest — and is Unavailable only if the
                                // row has no try left to compare, rather than linking to nothing.
                                //
                                // View Tries is a real link, and the drawer is an enhancement over it:
                                // with no script it opens the same fragment as a page of its own.
                        ?>
                                <div class="pr-actions__group">
                                    <?= $latestTry !== null
                                        ? $compare->action(
                                            $latestTry->demo->demoOrderId,
                                            'View comparison for ' . $practitioner->displayName() . "'s latest try",
                                        )
                                        : '<span class="util-muted" title="No comparison is available for this practitioner.">Unavailable</span>' ?>
                                    <a class="ot-rowaction" href="<?= Html::encode($triesUrl) ?>"
                                       data-pr-tries="<?= Html::encode($triesUrl) ?>"
                                       data-pr-agent="<?= Html::encode($practitioner->displayName()) ?>">View Tries</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <?php
            // Server-side paging, and the search and sort ride along in every link — so page two of
            // a search is page two OF THAT SEARCH, which is the bug a pager that forgets its filter
            // produces.
            ?>
            <nav class="pr-pager" aria-label="Practitioner pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn--sm" href="<?= Html::encode($pageUrl(['page' => $page - 1])) ?>">Previous</a>
                <?php endif; ?>
                <span class="pr-pager__at">Page <?= Html::encode((string) $page) ?>
                    of <?= Html::encode((string) $pages) ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn--sm" href="<?= Html::encode($pageUrl(['page' => $page + 1])) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($unmatchedCount > 0): ?>
    <?php
    /*
     * Unmatched results, in a section of their own.
     *
     * Never folded into a practitioner's row: an imported order nobody can be shown to have created
     * is not anybody's, and putting it under a name would be the invented attribution the importer
     * refuses to make. Listed bounded, because this is a prompt to investigate rather than a report.
     */
    ?>
    <div class="card a2t-wide pr-unmatched">
        <h2 class="card__title">
            Unmatched
            <span class="pr-unmatched__count"><?= Html::encode((string) $unmatchedCount) ?></span>
        </h2>
        <p class="util-muted pr-unmatched__note">
            Imported results that no tracked attempt could be shown to have produced. They are kept
            and scored, and credited to no one.
        </p>
        <div class="a2t-table-scroll">
            <?php
            // Its own modifier, so this table and the practitioner table above are distinguishable
            // to a stylesheet and to a test. Two tables sharing one class is how an assertion about
            // "the rows" quietly counts both.
    ?>
            <table class="table pr-table pr-table--unmatched">
                <?php
                // Sized like the practitioner table above it, so the two read as one page
                // rather than as two tables that happen to share a screen.
    ?>
                <colgroup>
                    <col class="pr-col-agent">
                    <col class="pr-col-score">
                    <col class="pr-col-when">
                    <col class="pr-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th>Demo order</th>
                        <th class="pr-num">Score</th>
                        <th>Imported</th>
                        <th><span class="util-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unmatched as $demo): ?>
                        <?php $unmatchedScore = $unmatchedScores[$demo->demoOrderId] ?? null; ?>
                        <tr>
                            <td data-ot-label="Demo order">#<?= Html::encode((string) $demo->demoOrderId) ?></td>
                            <td data-ot-label="Score" class="pr-num">
                                <?php
                    // The same canonical scorer the attributed rows use, and the
                    // same honesty about an unavailable one: a dash, never a zero.
                        ?>
                                <span class="pr-score pr-score--<?= Html::encode($band($unmatchedScore)) ?>"><?=
                            Html::encode($percent($unmatchedScore)) ?></span>
                            </td>
                            <td data-ot-label="Imported"><?= $demo->importedAt === null
                            ? '<span class="util-muted">&mdash;</span>'
                            : Html::encode($demo->importedAt) ?></td>
                            <td data-ot-label="Actions" class="pr-actions">
                                <?= $compare->action($demo->demoOrderId) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($unmatchedCount > count($unmatched)): ?>
            <p class="util-muted">
                Showing <?= Html::encode((string) count($unmatched)) ?>
                of <?= Html::encode((string) $unmatchedCount) ?>. The rest are on
                <a href="<?= Html::encode($allDemoUrl) ?>">All Demo Orders</a>.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
    /*
 * The drawer, empty until something is opened into it.
 *
 * One element for the whole table rather than one per row: a hundred rows would otherwise ship a
 * hundred hidden panels, and only ever one can be open. The script fills it from the same URL the
 * row's link points at, so what it shows and what the link would have shown are the same response.
 */
?>
<div class="pr-drawer" data-pr-drawer hidden>
    <div class="pr-drawer__scrim" data-pr-drawer-close></div>
    <aside class="pr-drawer__panel" role="dialog" aria-modal="true"
           aria-labelledby="pr-drawer-title" tabindex="-1" data-pr-drawer-panel>
        <header class="pr-drawer__head">
            <div>
                <h2 class="pr-drawer__title" id="pr-drawer-title" data-pr-drawer-title>Practitioner</h2>
                <p class="pr-drawer__meta">
                    Source order #<?= Html::encode((string) $sourceOrderId) ?>
                    &middot; <?= Html::encode($storeName) ?>
                </p>
            </div>
            <button class="pr-drawer__close" type="button" data-pr-drawer-close
                    aria-label="Close">&times;</button>
        </header>
        <div class="pr-drawer__body" data-pr-drawer-body>
            <p class="util-muted">Loading…</p>
        </div>
    </aside>
</div>
