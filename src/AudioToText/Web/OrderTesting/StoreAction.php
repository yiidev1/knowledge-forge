<?php

declare(strict_types=1);

namespace App\AudioToText\Web\OrderTesting;

use App\AudioToText\Domain\AudioActorInterface;
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
use App\AudioToText\Domain\MirroredOrderReaderInterface;
use App\AudioToText\Domain\DemoOrderLinkReaderInterface;
use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Domain\OrderVerificationReaderInterface;
use App\AudioToText\Domain\StoreOrderGroupRepositoryInterface;
use App\AudioToText\Domain\TopicAssignmentReaderInterface;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\Store\Action as AudioStoreAction;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
/*
 * Not `final`: Practice Orders is this same page over the same orders with a topic attached, and it
 * extends this class to inherit the demo-order column and the route seam rather than restating either.
 * The same reason `AudioStoreAction` above is not final — see its docblock.
 */
readonly class StoreAction extends AudioStoreAction
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
        AudioActorInterface $actor,
        Redirect $redirect,
        AppTimeZone $appTimeZone,
        StoreOrderGroupRepositoryInterface $groups,
        DemoOrderLinkReaderInterface $demoLinks,
        ArrivingRecordingMerger $arriving,
        ClockInterface $clock,
        /** This surface's own question: how many demo orders each order on the page produced. */
        private DemoOrderCountReaderInterface $demoOrderCounts,
        /**
         * The mirrored Order58 record behind each row — its Seq, and whether it exists.
         *
         * Read here rather than joined into the group query because the groups come from the audio
         * tables and an order id there is free text: a join would have to cast it, and a cast in a
         * join condition cannot use the index. One extra batched lookup is cheaper and says what it
         * means.
         */
        private MirroredOrderReaderInterface $mirroredOrders,
        /**
         * Which topic each order is practised under.
         *
         * Here rather than on a subclass because **this** is where topics are assigned. The practice
         * surfaces inherit the column and show it read-only; the controls that change it live on this
         * page and nowhere else.
         */
        private TopicAssignmentReaderInterface $topics,
        /**
         * Which orders on this page have been checked over.
         *
         * Asked only where the column is actually rendered — see {@see extraTemplateData()}. The
         * practice surfaces inherit this constructor and do not show verification, so they must not
         * pay a query for it.
         */
        private OrderVerificationReaderInterface $verifications,
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
            $actor,
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
     *
     * Item-synchronisation progress is deliberately NOT read here. That bar lives on
     * `/admin/order58/orders`, where it can count every stored order rather than only the handful this
     * page can open a comparison for; this surface pays for no query on its behalf.
     */
    protected function extraTemplateData(int $sourceId, array $orderIds): array
    {
        return [
            'demoOrderCounts' => $this->demoOrderCounts->countsFor($sourceId, $orderIds),
            'routes' => $this->surfaceRoutes(),
            /*
             * One query for the page, bounded by this store: the provider's Seq for each order, and
             * the fact that the order is mirrored at all — which is what decides whether a receipt
             * can be offered.
             *
             * Asked for when EITHER column is rendered. The two were gated on receipts alone, which
             * silently meant a surface showing Seq without receipts would have had the column and no
             * data to put in it. Still skipped entirely where neither is shown, so no surface pays
             * for a query it does not use.
             */
            'mirroredOrders' => $this->capabilities()->canViewOrderReceipts
                || $this->capabilities()->canViewOrderSeq
                    ? $this->mirroredOrders->forOrders($sourceId, $orderIds)
                    : [],
            // Two queries for the whole page, not two per row: the assignments for the orders being
            // shown, and the assignable topics once.
            'topicAssignments' => $this->topics->forOrders($sourceId, $orderIds),
            // Scoped to the store this page is about, so the dropdown offers exactly what the
            // assignment endpoint will accept.
            'assignableTopics' => $this->topics->assignableTopics($sourceId),
            // One query for the whole page, and only on the surface that renders the column: an order
            // with no row is unverified, which the template already knows how to say.
            'orderVerifications' => $this->capabilities()->canVerifyOrders
                ? $this->verifications->forOrders($sourceId, $orderIds)
                : [],
            // The template renders the topic column when this is true; whether that column can be
            // *used* is a separate question, answered by the capabilities.
            'practiceMode' => true,
        ];
    }

    /**
     * Which topics the reader asked for, as a set.
     *
     * Parsed by {@see OrderGroupFilter::fromQuery()} rather than here, so the rule that decides what a
     * filter value means has one home and the picker, the pager's links and the SQL cannot each decide
     * it differently. Anything unrecognised filters nothing.
     */
    protected function groupFilter(ServerRequestInterface $request): ?OrderGroupFilter
    {
        return OrderGroupFilter::fromQuery($request->getQueryParams()[OrderGroupFilter::PARAM] ?? null);
    }

    /**
     * The addresses this surface's page builds its own links from.
     *
     * The template is shared with Practice Orders, which is the same table over the same orders with a
     * topic attached. Everything about *what* a row is stays identical; only where its links go differs,
     * and that is these nine names. Passing them in rather than printing constants is what lets one
     * template serve both without either becoming a mode of the other — and without a second copy of
     * seventeen hundred lines to keep in step.
     *
     * Route names, not classes, as everywhere else: a name is not a namespace.
     *
     * @return array<string, string>
     */
    protected function surfaceRoutes(): array
    {
        return [
            'page' => OrderTestingRoute::PAGE,
            'pageLabel' => 'Manage Orders',
            'store' => OrderTestingRoute::STORE,
            'arriving' => OrderTestingRoute::STORE_ARRIVING,
            'demoUrl' => OrderTestingRoute::DEMO_URL,
            'recordings' => OrderTestingRoute::STORE_GROUP_RECORDINGS,
            'transcripts' => OrderTestingRoute::STORE_GROUP_TRANSCRIPTS,
            'demoOrders' => OrderTestingRoute::DEMO_ORDERS,
            'syncDemo' => OrderTestingRoute::SYNC_DEMO_ORDERS,
            // Where a topic is put on an order, and where several are put at once. Both live on this
            // surface; the practice surfaces inherit the names and never render a control that uses
            // them, and their own route groups do not mount them.
            'topicAssign' => OrderTestingRoute::TOPIC_ASSIGN,
            'topicBulk' => OrderTestingRoute::TOPIC_BULK,
            // Where an order is marked as checked over. Named here like the two above, and mounted in
            // only this surface's route group, so a practice page that somehow rendered the control
            // would still have nothing to post to.
            'verification' => OrderTestingRoute::VERIFICATION,

            // The original order's Order58 receipt. Named here like the rest, and mounted only in this
            // surface's route group — a practice page that inherited the column would render a link
            // its own group does not serve, so the column is gated on capability rather than on luck.
            'orderReceipt' => OrderTestingRoute::ORDER_RECEIPT,

            // The job-scoped addresses the row controls and the conversation dialog fetch. Here rather
            // than printed from the audio module's constants, because each surface mounts them at its
            // own path: an agent reading a page that named the administrator address would be handed a
            // URL their realm cannot reach, and the dialog would never fill.
            'jobOriginalFile' => AudioToTextRoute::JOB_ORIGINAL_FILE,
            'jobFragment' => AudioToTextRoute::JOB_REVIEW_FRAGMENT,
            'jobTranscribe' => AudioToTextRoute::JOB_TRANSCRIBE,
            'jobStatus' => AudioToTextRoute::JOB_STATUS,
            'jobReview' => AudioToTextRoute::JOB_REVIEW,
        ];
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
