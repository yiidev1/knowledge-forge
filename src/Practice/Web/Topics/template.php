<?php

declare(strict_types=1);

use App\Practice\Domain\StoreOption;
use App\Practice\Domain\Topic;
use App\Practice\Domain\TopicStatus;
use App\Practice\Web\PracticeAsset;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Topic Management: the list, and the one dialog that both adds and edits.
 *
 * ## One form, two jobs
 *
 * Add and Edit are the same fields posting to different addresses, so they are the same markup with a
 * different `action` — two templates would be two places for a field to be forgotten.
 *
 * ## The dialog opens from the URL
 *
 * `?topic=new` or `?topic=<id>` prints `open` here, server-side. So the page works with JavaScript
 * off, a validation failure comes back with the dialog still open and the typed values still in it, and
 * every trigger is a real link. JavaScript only upgrades the open to `showModal()`.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var Yiisoft\Yii\View\Renderer\Csrf $csrf
 * @var list<Topic> $topics
 * @var int $total
 * @var int $page
 * @var int $pageCount
 * @var string $search
 * @var TopicStatus|null $status
 * @var bool $creating
 * @var Topic|null $editing
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<StoreOption> $storeOptions every selectable store, for the dialog's Store field
 */

$assetManager->register(PracticeAsset::class);

$this->setTitle('Topic Management');
$this->setParameter('breadcrumbs', [['label' => 'Topic Management']]);

$base = $urlGenerator->generate('practice.topics');

/**
 * A URL carrying the current filters, omitting anything sitting at its default.
 *
 * `topic` is one of the four on purpose: it is what opens the dialog server-side, so an Edit link that
 * dropped it would land on the plain list and the dialog would open empty. Every key this builds from
 * is declared here, and an override naming anything else is ignored rather than silently appended.
 */
$listUrl = static function (array $overrides) use ($base, $search, $status, $page): string {
    $state = [
        'q' => $search,
        'status' => $status?->value ?? '',
        'page' => (string) $page,
        'topic' => '',
    ];

    foreach ($overrides as $key => $value) {
        if (array_key_exists($key, $state)) {
            $state[$key] = (string) $value;
        }
    }

    $params = [];

    if ($state['q'] !== '') {
        $params['q'] = $state['q'];
    }

    if ($state['status'] !== '') {
        $params['status'] = $state['status'];
    }

    if ((int) $state['page'] > 1) {
        $params['page'] = $state['page'];
    }

    if ($state['topic'] !== '') {
        $params['topic'] = $state['topic'];
    }

    return $params === [] ? $base : $base . '?' . http_build_query($params);
};

$dialogOpen = $creating || $editing !== null;
$editingTopic = $editing;

// What the form shows: what was typed if a submission bounced, otherwise the stored row.
$fieldValue = static function (string $field, ?Topic $topic) use ($values): string {
    if (isset($values[$field])) {
        return $values[$field];
    }

    if ($topic === null) {
        return '';
    }

    return match ($field) {
        'name' => $topic->name,
        'description' => $topic->description ?? '',
        'status' => $topic->status->value,
        // Empty string is global — the same thing the "Global (All Stores)" option posts, so a
        // bounced form and a freshly opened Edit dialog agree about what no store looks like.
        'store_source_id' => $topic->storeSourceId === null ? '' : (string) $topic->storeSourceId,
        default => '',
    };
};

$errorClass = static fn(string $field): string => isset($errors[$field]) ? ' field__control--error' : '';
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Topic Management</h1>
        <p class="page-header__subtitle">
            Training topics a practice order can be assigned to. Deleting one keeps the orders that
            already carry it — the training record stays readable.
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn btn--primary" href="<?= Html::encode($listUrl(['topic' => 'new', 'page' => 1])) ?>"
           data-practice-dialog-open="practice-topic-dialog"
           data-practice-dialog-for="new">+ Add Topic</a>
    </div>
</div>

<div class="dir-toolbar">
    <form class="dir-search" method="get" action="<?= Html::encode($base) ?>" role="search">
        <?php
        // A GET form submits only its own fields, so the other axis travels as a hidden input.
        // `page` deliberately does not: a new search is a new result set.
?>
        <?php if ($status !== null): ?>
            <input type="hidden" name="status" value="<?= Html::encode($status->value) ?>">
        <?php endif; ?>
        <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
               placeholder="Search topics by name" aria-label="Search topics">
        <button class="btn btn--secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="<?= Html::encode($listUrl(['q' => '', 'page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php
// Three chips, and deliberately not four. A deleted topic is not a state an administrator curates —
// it is gone from this screen, and the orders that kept it show it on their own rows. Listing an
// "Archived" tab here would put the storage's word for the delete back on the screen.
?>
<div class="filter-bar" role="group" aria-label="Filter topics">
    <a class="filter-chip<?= $status === null ? ' filter-chip--active' : '' ?>"
       href="<?= Html::encode($listUrl(['status' => '', 'page' => 1])) ?>">All Topics</a>
    <?php foreach ([TopicStatus::Active, TopicStatus::Inactive] as $option): ?>
        <a class="filter-chip<?= $status === $option ? ' filter-chip--active' : '' ?>"
           href="<?= Html::encode($listUrl(['status' => $option->value, 'page' => 1])) ?>">
            <?= Html::encode($option->label()) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($topics === []): ?>
    <div class="empty">
        <div class="empty__icon" aria-hidden="true">🏷</div>
        <div class="empty__title">
            <?= $search !== '' || $status !== null ? 'No topics match that' : 'No topics yet' ?>
        </div>
        <p>
            <?php if ($search !== '' || $status !== null): ?>
                Try a different search, or clear the filters.
            <?php else: ?>
                Create a topic, then assign it to practice orders from a store's page.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <?php // Scrolls rather than wraps on a narrow screen: the desktop alignment is the one to keep.?>
        <div class="practice-topics-scroll">
        <table class="table practice-topics">
            <?php
            // Declared once, here, rather than inferred from whatever the widest cell happens to hold.
            // The four bounded columns are sized to their longest content and the name takes the rest.
    ?>
            <colgroup>
                <col class="practice-col-name">
                <?php // One `col` per header, in the header's order: a colgroup one short does not?>
                <?php // leave the last column unsized, it shifts every width one place left.?>
                <col class="practice-col-store">
                <col class="practice-col-status">
                <col class="practice-col-count">
                <col class="practice-col-created">
                <col class="practice-col-actions">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">Topic Name</th>
                    <?php // Which store may use this topic. "Global" is every store, and is what?>
                    <?php // every topic was before the column existed.?>
                    <th scope="col">Store</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="practice-num">Assigned Orders</th>
                    <th scope="col">Created At</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($topics as $topic): ?>
                <tr>
                    <td>
                        <?php
                // The name opens the same read-only details dialog the Practice Orders rows
                // use. Everything it shows is already in this row, so it costs no request —
                // and the count, which this page has already counted for the whole page, rides
                // along rather than being asked for again.
                ?>
                        <button class="practice-topic__name" type="button"
                                data-practice-topic-details
                                data-practice-topic-name="<?= Html::encode($topic->name) ?>"
                                data-practice-topic-description="<?= Html::encode($topic->description ?? '') ?>"
                                data-practice-topic-status="<?= Html::encode($topic->status->label()) ?>"
                                data-practice-topic-orders="<?= $topic->assignedOrders ?>"
                                data-practice-topic-note=""
                                title="View topic details"><?= Html::encode($topic->name) ?></button>
                        <?php if ($topic->description !== null): ?>
                            <div class="field__hint"><?= Html::encode($topic->description) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        /*
                         * The store's name, never its number.
                         *
                         * Resolved in the listing's own query — one LEFT JOIN for the page rather
                         * than a lookup per row. A topic whose store has since left the mirror still
                         * has to render, so the id is the fallback: it is the one fact still known
                         * about where that topic belongs, and a blank would read as global, which
                         * would be a lie.
                         */
                ?>
                        <?php if ($topic->isGlobal()): ?>
                            <span class="badge badge--muted">Global</span>
                        <?php elseif ($topic->storeName !== null): ?>
                            <?= Html::encode($topic->storeName) ?>
                            <div class="field__hint">#<?= Html::encode((string) $topic->storeSourceId) ?></div>
                        <?php else: ?>
                            <span class="util-muted">Store #<?= Html::encode((string) $topic->storeSourceId) ?></span>
                            <div class="field__hint">Not in the store mirror</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php // Green for offered, red for not. The two states an administrator acts on.?>
                        <span class="badge badge--<?= $topic->status === TopicStatus::Active ? 'success' : 'error' ?>">
                            <?= Html::encode($topic->status->label()) ?>
                        </span>
                    </td>
                    <td class="practice-num"><?= Html::encode((string) $topic->assignedOrders) ?></td>
                    <td class="util-muted"><?= Html::encode($topic->createdAt ?? '—') ?></td>
                    <td class="practice-actions">
                        <?php
                // The link carries the topic in the URL, and `data-practice-dialog-for` tells
                // the script which topic it wants. The script opens the dialog only when the
                // one already on the page is that topic's; otherwise it lets the browser
                // follow the link, and the server renders the dialog filled in.
                ?>
                        <a class="btn btn--secondary btn--sm"
                           href="<?= Html::encode($listUrl(['topic' => (string) $topic->id])) ?>"
                           data-practice-dialog-open="practice-topic-dialog"
                           data-practice-dialog-for="<?= $topic->id ?>">Edit</a>

                        <?php $activating = $topic->status !== TopicStatus::Active; ?>
                        <form method="post"
                              action="<?= Html::encode($urlGenerator->generate('practice.topics.status', ['id' => $topic->id])) ?>">
                            <?= $csrf->hiddenInput() ?>
                            <input type="hidden" name="status"
                                   value="<?= $activating ? TopicStatus::Active->value : TopicStatus::Inactive->value ?>">
                            <button class="btn btn--sm <?= $activating ? 'btn--success' : 'btn--warning' ?>"
                                    type="submit"><?= $activating ? 'Activate' : 'Deactivate' ?></button>
                        </form>

                        <?php
                // The confirmation says what actually happens rather than asking "are you
                // sure?": the orders that carry this topic keep it, and keep reading correctly.
                ?>
                        <form method="post"
                              action="<?= Html::encode($urlGenerator->generate('practice.topics.delete', ['id' => $topic->id])) ?>"
                              data-practice-confirm="Delete &quot;<?= Html::encode($topic->name) ?>&quot;? <?= $topic->assignedOrders > 0
                          ? 'The ' . $topic->assignedOrders . ' order(s) already practised under it keep it and stay readable; it can no longer be assigned to new orders.'
                          : 'It can no longer be assigned to orders.' ?>">
                            <?= $csrf->hiddenInput() ?>
                            <button class="btn btn--sm btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
        'page' => $page,
        'pageCount' => $pageCount,
        'pageUrl' => static fn(int $p): string => $listUrl(['page' => $p]),
    ]) ?>
<?php endif; ?>

<?php
// Shared with the Practice Orders table, which opens the same dialog from its own rows. Rendered
// whether or not the list is empty: it is filled by the trigger that was clicked, and costs nothing
// when nothing is clicked.
?>
<?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/topic-details-dialog') ?>

<?= $this->render(__DIR__ . '/_dialog', [
    'csrf' => $csrf,
    'urlGenerator' => $urlGenerator,
    'open' => $dialogOpen,
    'editing' => $editingTopic,
    'closeUrl' => $listUrl(['topic' => '']),
    'fieldValue' => $fieldValue,
    'errorClass' => $errorClass,
    'errors' => $errors,
    'storeOptions' => $storeOptions,
]) ?>
