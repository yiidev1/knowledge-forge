<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\Agent\Domain\PracticeStore;
use App\Agent\Domain\PracticeStoreDirectoryInterface;
use App\Shared\Web\Support\AlphabetIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_filter;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function is_string;
use function max;
use function mb_stripos;
use function min;
use function trim;

/**
 * The stores an agent may practise on, A–Z.
 *
 * ## Deliberately the same shape as the store-chat picker, and a different list
 *
 * Search, letter counts and paging behave exactly as on the agent home, because an agent should not
 * have to learn a second way to find a store. What differs is which stores are in it: this one asks
 * {@see PracticeStoreDirectoryInterface}, whose predicate is about recordings and mirrored orders, not
 * about chat readiness. A store that is perfectly chattable and holds no order-linked audio does not
 * appear here, and one whose chat was never provisioned does.
 *
 * The whole qualifying list is read once and narrowed in PHP, as the store-chat picker already does:
 * the letter counts have to reflect what the search matched, which means counting across the matched
 * set rather than the page. The mirrored store table is small and the predicate runs once.
 */
final readonly class Action
{
    private const PER_PAGE = 24;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private PracticeStoreDirectoryInterface $directory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? trim((string) $query['q']) : '';
        $letter = AlphabetIndex::normalize(is_string($query['letter'] ?? null) ? (string) $query['letter'] : null);
        $page = is_string($query['page'] ?? null) ? max(1, (int) $query['page']) : 1;

        $available = $this->directory->findAvailable();

        // Search narrows the working set first; the letter counts then reflect what the search matched,
        // so switching letters never surfaces a store the search excluded.
        $matched = $search === '' ? $available : array_values(array_filter(
            $available,
            static fn(PracticeStore $s): bool => self::matches($s, $search),
        ));

        $counts = [AlphabetIndex::ALL => count($matched)];

        foreach ($matched as $store) {
            $bucket = AlphabetIndex::letterFor($store->name);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        $visible = $letter === AlphabetIndex::ALL ? $matched : array_values(array_filter(
            $matched,
            static fn(PracticeStore $s): bool => AlphabetIndex::letterFor($s->name) === $letter,
        ));

        $pageCount = max(1, (int) ceil(count($visible) / self::PER_PAGE));
        $page = min($page, $pageCount);

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(__DIR__ . '/template', [
                'stores' => array_slice($visible, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                'counts' => $counts,
                'letter' => $letter,
                'search' => $search,
                'page' => $page,
                'pageCount' => $pageCount,
                'totalMatched' => count($visible),
                'totalAvailable' => count($available),
            ]);
    }

    private static function matches(PracticeStore $store, string $needle): bool
    {
        foreach ([$store->name, $store->company ?? '', (string) $store->sourceId] as $field) {
            if ($field !== '' && mb_stripos($field, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
