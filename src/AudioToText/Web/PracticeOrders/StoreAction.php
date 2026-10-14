<?php

declare(strict_types=1);

namespace App\AudioToText\Web\PracticeOrders;

use App\AudioToText\Application\ArrivingRecordingMerger;
use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\AudioUploadValidator;
use App\AudioToText\Application\SeparateUploadValidator;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Application\UploadOptions;
use App\AudioToText\Application\WorkerHealthService;
use App\AudioToText\Domain\AudioActorInterface;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\DemoOrderCountReaderInterface;
use App\AudioToText\Domain\DemoOrderLinkReaderInterface;
use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Domain\StoreOrderGroupRepositoryInterface;
use App\AudioToText\Domain\TopicAssignmentReaderInterface;
use App\AudioToText\Web\OrderTesting\StoreAction as OrderTestingStoreAction;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One store's practice orders.
 *
 * ## The same page, one map of addresses different
 *
 * This renders the **Order Testing template**, not a copy of it. Seventeen hundred lines decide what a
 * row is, how a recording plays, how a conversation opens and what every dialog says; none of that
 * differs here, and a second copy would be a second thing to keep correct. What differs is where the
 * links go and two extra columns, so {@see surfaceRoutes()} supplies the addresses and
 * {@see extraTemplateData()} supplies the topics.
 *
 * ## Group and job endpoints stay on the Order Testing addresses, for now
 *
 * Transcripts, Manage Audio and the arriving poll are reached through `/order-testing/...`. Both realms
 * that can open this page — administrator and operation — already reach those, and mounting a second
 * copy of four routes to answer identically would be four more things to keep in step. The Agent
 * surface in stage two cannot do that, and will mount its own.
 *
 * ## It owns no data
 *
 * Orders, recordings, conversations, demo orders and comparisons are read through the same services
 * Order Testing uses. The only thing this surface adds is which topic a source order is being practised
 * under, and that is a row in a table of its own keyed on the order — never a copy of the order.
 */
final readonly class StoreAction extends OrderTestingStoreAction
{
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
        DemoOrderCountReaderInterface $demoOrderCounts,
        /** This surface's own column: which topic each order is practised under. */
        private TopicAssignmentReaderInterface $topics,
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
            $demoOrderCounts,
        );
    }

    /**
     * The parent's data, plus the topic each row carries and the list the dialog offers.
     *
     * Two queries for the whole page, not two per row: the assignments for the orders being shown, and
     * the assignable topics once.
     */
    protected function extraTemplateData(int $sourceId, array $orderIds): array
    {
        return parent::extraTemplateData($sourceId, $orderIds) + [
            'topicAssignments' => $this->topics->forOrders($sourceId, $orderIds),
            'assignableTopics' => $this->topics->assignableTopics(),
            // The template renders the Select and Set Topic columns only when this is true, which is
            // how one template serves a surface that assigns topics and one that does not.
            'practiceMode' => true,
        ];
    }

    /**
     * The `?topic=` filter, which is the one question this surface lets the reader ask of the list.
     *
     * Parsed by {@see OrderGroupFilter::fromQuery()} rather than here, so the rule that decides what a
     * filter value means has one home and the template's control, the pager's links and the SQL cannot
     * each decide it differently. Anything unrecognised filters nothing.
     */
    protected function groupFilter(ServerRequestInterface $request): ?OrderGroupFilter
    {
        return OrderGroupFilter::fromQuery($request->getQueryParams()['topic'] ?? null);
    }

    protected function surfaceRoutes(): array
    {
        // Literals, like every cross-surface link in this file's neighbours: a route name is not a
        // namespace, so naming the Practice Orders pages here costs this module no dependency.
        // Start from the inherited map and replace only the three addresses that belong to this
        // surface. Group and job endpoints stay on the Order Testing ones; see the class docblock.
        return array_merge(parent::surfaceRoutes(), [
            'page' => 'practice-orders',
            'pageLabel' => 'Practice Orders',
            'store' => 'practice-orders.store',
        ]);
    }

    protected function storeMissing(): ResponseInterface
    {
        return $this->redirect->toRoute('practice-orders');
    }

    protected function afterUpload(string $conversationId, int $sourceId): ResponseInterface
    {
        return $this->redirect->afterPost('practice-orders.store', ['sourceId' => $sourceId]);
    }
}
