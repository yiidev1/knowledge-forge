<?php

declare(strict_types=1);

namespace App\AudioToText\Web\OrderTesting;

use App\AudioToText\Application\ArrivingRecordingMerger;
use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\AudioUploadValidator;
use App\AudioToText\Application\SeparateUploadValidator;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Application\UploadOptions;
use App\AudioToText\Application\WorkerHealthService;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\DemoOrderCountReaderInterface;
use App\AudioToText\Domain\DemoOrderLinkReaderInterface;
use App\AudioToText\Domain\StoreOrderGroupRepositoryInterface;
use App\AudioToText\Web\Job\Store\Action as AudioStoreAction;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One store's recordings, on the Order Testing surface.
 *
 * ## Inherited, not copied
 *
 * Reading a store's orders is five careful queries, a pager, an upload form with its own validation and
 * a policy that decides what each recording type becomes. None of that is presentation, and a second
 * copy would be a second place for those rules to drift. So this inherits all of it and changes the
 * three things that are genuinely about *which surface the operator is on*:
 *
 * - the template, so columns, wording and controls can change here without touching the audio page;
 * - where a store id that no longer resolves sends them;
 * - where an accepted upload lands.
 *
 * ## Why an upload returns here rather than to the conversion page
 *
 * The audio page sends an upload on to the conversion screen, which is the right answer there: an
 * administrator who has just uploaded one recording wants to watch it process. Order Testing is worked
 * through an order at a time, and the conversion screen lives at an `/audio-to-text/...` URL — so sending
 * an operator there would move them onto the surface this one exists to keep them off. They come back to
 * the store page, where the row they just added to is waiting.
 */
final readonly class StoreAction extends AudioStoreAction
{
    /**
     * The parent's dependencies, restated, plus the one this surface adds.
     *
     * Verbose on purpose. The alternative — giving the shared page the demo-order reader so the
     * subclass needs no constructor — would make the Audio-to-Text store page run an extra query on
     * every render for a column it does not show. This surface pays for its own column.
     *
     * Restating the list also means a change to the parent's constructor fails here loudly at
     * construction rather than being absorbed by a variadic that silently passes the wrong things.
     */
    public function __construct(
        WebViewRenderer $viewRenderer,
        AudioStoreLookupInterface $stores,
        AudioConversationRepositoryInterface $conversations,
        AudioUploadValidator $validator,
        SeparateUploadValidator $separateValidator,
        TranscriptionQueue $queue,
        WorkerHealthService $workerHealth,
        AudioToTextSettings $settings,
        AudioToTextSettingsRepositoryInterface $settingsRepository,
        UploadOptions $uploadOptions,
        CurrentAdmin $currentAdmin,
        Redirect $redirect,
        AppTimeZone $appTimeZone,
        StoreOrderGroupRepositoryInterface $groups,
        DemoOrderLinkReaderInterface $demoLinks,
        ArrivingRecordingMerger $arriving,
        ClockInterface $clock,
        /** This surface's own question: how many demo orders each order on the page produced. */
        private DemoOrderCountReaderInterface $demoOrderCounts,
    ) {
        parent::__construct(
            $viewRenderer,
            $stores,
            $conversations,
            $validator,
            $separateValidator,
            $queue,
            $workerHealth,
            $settings,
            $settingsRepository,
            $uploadOptions,
            $currentAdmin,
            $redirect,
            $appTimeZone,
            $groups,
            $demoLinks,
            $arriving,
            $clock,
        );
    }

    /**
     * The demo-order counts behind this surface's extra column.
     *
     * One query for the whole page, keyed by the order id each row shows — the same shape the demo
     * links already use, and for the same reason: twenty rows would otherwise be twenty queries.
     */
    protected function extraTemplateData(int $sourceId, array $orderIds): array
    {
        return ['demoOrderCounts' => $this->demoOrderCounts->countsFor($sourceId, $orderIds)];
    }

    protected function storeMissing(): ResponseInterface
    {
        return $this->redirect->toRoute(OrderTestingRoute::PAGE);
    }

    protected function afterUpload(string $conversationId, int $sourceId): ResponseInterface
    {
        return $this->redirect->afterPost(OrderTestingRoute::STORE, ['sourceId' => $sourceId]);
    }

    protected function templatePath(): string
    {
        return __DIR__ . '/template';
    }
}
