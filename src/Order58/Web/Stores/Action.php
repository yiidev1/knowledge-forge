<?php

declare(strict_types=1);

namespace App\Order58\Web\Stores;

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
 * The admin Order58 store directory (GET /admin/order58/stores): a full-width, searchable, alphabetised,
 * filterable grid of every mirrored store joined to its knowledge base. All narrowing is server-side, so
 * the page stays fast regardless of store volume.
 */
final readonly class Action
{
    private const PER_PAGE = 24;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private StoreDirectoryReaderInterface $reader,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $rawSearch = $params['q'] ?? null;
        $rawFilter = $params['filter'] ?? null;
        $rawLetter = $params['letter'] ?? null;
        $rawPage = $params['page'] ?? null;

        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $filter = StoreDirectoryFilter::fromRequest(is_string($rawFilter) ? $rawFilter : null);
        $sourceStatus = StoreSourceStatusFilter::fromRequest(is_string($params['status'] ?? null) ? (string) $params['status'] : null);
        $agent = StoreAgentAvailabilityFilter::fromRequest(is_string($params['agent'] ?? null) ? (string) $params['agent'] : null);
        $letter = AlphabetIndex::normalize(is_string($rawLetter) ? $rawLetter : null);
        $page = is_string($rawPage) ? max(1, (int) $rawPage) : 1;

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

        // Clamp an out-of-range page (e.g. after a filter change) to the last page.
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
