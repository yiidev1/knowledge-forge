<?php

declare(strict_types=1);

namespace App\Order58\Web\StoreChat;

use App\Order58\Domain\StoreAgentAvailabilityFilter;
use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Web\Support\AlphabetIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function min;
use function trim;

/**
 * The admin "Store chat" picker (GET /admin/order58/store-chat): an alphabetical, searchable, filterable list of
 * every mirrored store. Chat-eligible stores link straight to chat; ineligible ones are shown disabled with the
 * reason. Same directory machinery, filters and canonical eligibility as the store list.
 */
final readonly class Action
{
    private const PER_PAGE = 36;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private StoreDirectoryReaderInterface $reader,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $search = is_string($params['q'] ?? null) ? trim((string) $params['q']) : '';
        $filter = StoreDirectoryFilter::fromRequest(is_string($params['filter'] ?? null) ? (string) $params['filter'] : null);
        $sourceStatus = StoreSourceStatusFilter::fromRequest(is_string($params['status'] ?? null) ? (string) $params['status'] : null);
        $agent = StoreAgentAvailabilityFilter::fromRequest(is_string($params['agent'] ?? null) ? (string) $params['agent'] : null);
        $letter = AlphabetIndex::normalize(is_string($params['letter'] ?? null) ? (string) $params['letter'] : null);
        $page = is_string($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;

        $build = static fn(int $p): StoreDirectoryQuery => new StoreDirectoryQuery(
            $search,
            $filter,
            $letter,
            $p,
            self::PER_PAGE,
            false,
            $sourceStatus,
            $agent,
        );
        $result = $this->reader->search($build($page));
        if ($page > $result->pageCount()) {
            $result = $this->reader->search($build($result->pageCount()));
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'result' => $result,
                'search' => $search,
                'filter' => $filter,
                'sourceStatus' => $sourceStatus,
                'agent' => $agent,
                'letter' => $letter,
                'page' => min($page, $result->pageCount()),
            ]);
    }
}
