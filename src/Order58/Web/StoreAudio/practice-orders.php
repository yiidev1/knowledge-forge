<?php

declare(strict_types=1);

use App\Order58\Domain\StoreAudioBreakdown;
use App\Order58\Domain\StoreAudioFilter;
use App\Order58\Domain\StoreDirectoryResult;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * The store picker, for Practice Orders.
 *
 * A page header and one render call. Which stores appear, how they are searched, filtered, lettered,
 * paged and counted is the action's and the shared body's — see `_picker-body.php` — so this surface
 * cannot drift from the other two on what a store is or how many recordings it holds.
 *
 * What is deliberately absent: the transcription-settings dialog. That writes the server-wide default
 * for every transcription in the application, which is administration rather than training, and it
 * already has a home on the Order Testing picker. Offering it here would also offer it to operation
 * users, whose POST would land in the administrators' route group and bounce to a login form.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var StoreDirectoryResult $result
 * @var string $search
 * @var StoreSourceStatusFilter $sourceStatus
 * @var StoreAudioFilter $audio
 * @var StoreAudioFilter $audioDefault
 * @var string $letter
 * @var int $page
 * @var array<int, StoreAudioBreakdown> $audioCounts
 * @var array<int, int> $topicCounts how many of each store's orders carry a topic
 */

$this->setTitle('Practice Orders');
$this->setParameter('breadcrumbs', [
    ['label' => 'Practice Orders'],
]);

// Every filter, the search and the pager post back to this page. One line, and the reason the three
// pickers cannot send each other's traffic.
$base = $urlGenerator->generate('practice-orders');

/**
 * @param array<string, string|int> $overrides
 */
$dirUrl = static function (array $overrides) use ($base, $search, $sourceStatus, $audio, $audioDefault, $letter, $page): string {
    $state = [
        'q' => $search,
        'status' => $sourceStatus->value,
        'audio' => $audio->value,
        'letter' => $letter,
        'page' => $page,
    ];

    foreach ($overrides as $key => $value) {
        $state[$key] = (string) $value;
    }

    $params = [];

    if ($state['q'] !== '') {
        $params['q'] = $state['q'];
    }

    if ($state['status'] !== StoreSourceStatusFilter::All->value) {
        $params['status'] = $state['status'];
    }

    // Against this surface's landing value, not against `All`: on a picker that opens filtered, the
    // chip that widens the list is the one that has to say so out loud.
    if ($state['audio'] !== $audioDefault->value) {
        $params['audio'] = $state['audio'];
    }

    if ($state['letter'] !== AlphabetIndex::ALL) {
        $params['letter'] = $state['letter'];
    }

    if ((int) $state['page'] > 1) {
        $params['page'] = (string) $state['page'];
    }

    return $params === [] ? $base : $base . '?' . http_build_query($params);
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Practice Orders</h1>
        <p class="page-header__subtitle">
            Pick a store to practise against. Each store's orders carry the topic they are being
            practised under.
        </p>
    </div>
    <div class="page-header__actions">
        <?php
        // The same importer as Order Testing, under the same lock, called through this surface's own
        // address so that finishing returns the operator here. There is no second importer and no
        // second schedule — see DemoOrderSyncService.
        //
        // No Topic Management link either, for the same reason the settings dialog is absent: that page
        // is in the administrators' route group, and an operation user following it would be bounced to
        // a login form they cannot use. The sidebar already shows it to the realms that can open it.
?>
        <form method="post"
              action="<?= Html::encode($urlGenerator->generate('order-testing.sync-demo-orders')) ?>">
            <?= $csrf->hiddenInput() ?>
            <input type="hidden" name="return" value="practice-orders">
            <button class="btn btn--secondary" type="submit" data-busy-label="Syncing…">Sync demo orders</button>
        </form>
    </div>
</div>

<?= $this->render(__DIR__ . '/_picker-body', [
    'urlGenerator' => $urlGenerator,
    'result' => $result,
    'search' => $search,
    'sourceStatus' => $sourceStatus,
    'audio' => $audio,
    'audioDefault' => $audioDefault,
    'letter' => $letter,
    'page' => $page,
    'audioCounts' => $audioCounts,
    'topicCounts' => $topicCounts,
    'base' => $base,
    'dirUrl' => $dirUrl,
    'storeRoute' => 'practice-orders.store',
    'cta' => 'View Practice Orders',
]) ?>
