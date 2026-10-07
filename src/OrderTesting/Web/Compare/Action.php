<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Compare;

use App\OrderTesting\Application\Comparison\DemoOrderNormalizer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One demo order beside the source order it was recreated from.
 *
 * ## All three ids, and the lookup is the boundary
 *
 * Store, source order and demo order all come from the route and all three go into
 * {@see DemoOrderRepositoryInterface::findOne()}. Changing any one of them to another merchant's value
 * produces the same 404 as an id that never existed — the narrow lookup is what enforces that, not a
 * check afterwards that somebody could forget to write.
 *
 * ## The left-hand side may legitimately be empty
 *
 * The demo order is a file somebody's listener wrote; the source order is mirrored from an API over a
 * date range somebody asked for. The second can be absent while the first exists, and that is not an
 * error — the page says the mirror does not hold it and still shows the trainee's work in full. See
 * {@see OrderComparator}.
 *
 * ## No score
 *
 * This page renders an {@see \App\OrderTesting\Domain\Comparison\OrderComparison}: two values and a
 * status per field, and nothing that adds them up. Scoring is a separate, separately specified piece of
 * work and will read that object rather than this template.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private DemoOrderRepositoryInterface $demoOrders,
        private SourceOrderReaderInterface $sourceOrders,
        private TestAttemptRepositoryInterface $attempts,
        private StoreReaderInterface $stores,
        private DemoOrderNormalizer $demoNormalizer,
        private OrderComparator $comparator,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
        #[RouteArgument]
        int $demoOrderId,
    ): ResponseInterface {
        $storeName = $this->stores->nameOf($sourceId);

        if ($storeName === null) {
            throw new NotFoundException('store_not_found', 'No store has that source id.');
        }

        $demo = $this->demoOrders->findOne($sourceId, $sourceOrderId, $demoOrderId);

        if ($demo === null) {
            throw new NotFoundException(
                'demo_order_not_found',
                'No demo order with that id belongs to this store and source order.',
            );
        }

        $attempt = $demo->matchedAttemptId === null
            ? null
            : ($this->attempts->byIds([$demo->matchedAttemptId])[$demo->matchedAttemptId] ?? null);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'demo' => $demo,
                'attempt' => $attempt,
                'comparison' => $this->comparator->compare(
                    $this->sourceOrders->find($sourceId, $sourceOrderId),
                    $this->demoNormalizer->normalize($demo),
                ),
            ]);
    }
}
