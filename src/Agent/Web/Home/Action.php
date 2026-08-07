<?php

declare(strict_types=1);

namespace App\Agent\Web\Home;

use App\Agent\Domain\AgentStore;
use App\Agent\Domain\AgentStoreDirectoryInterface;
use App\Shared\Web\Support\AlphabetIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

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
 * The agent landing page (GET /agent): every store the agent can chat with — the canonical chat-eligibility
 * (source active, KB ready, a usable indexed document, store profile) AND the admin's agent_enabled switch,
 * enforced in {@see \App\Agent\Infrastructure\DbAgentStoreDirectory}. The same list for every active agent
 * (`account_id` is never consulted). This eligible set is bounded by that predicate, so search / A–Z / paging
 * run over it in memory; the heavy full-directory query is the admin surface, not this one.
 */
final readonly class Action
{
    private const PER_PAGE = 24;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentStoreDirectoryInterface $directory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? trim((string) $query['q']) : '';
        $letter = AlphabetIndex::normalize(is_string($query['letter'] ?? null) ? (string) $query['letter'] : null);
        $page = is_string($query['page'] ?? null) ? max(1, (int) $query['page']) : 1;

        $available = $this->directory->findAvailable();

        // Search narrows the working set first; the letter counts then reflect what the search matched, so
        // switching letters never surfaces a store the search excluded.
        $matched = $search === '' ? $available : array_values(array_filter(
            $available,
            static fn(AgentStore $s): bool => self::matches($s, $search),
        ));

        $counts = [AlphabetIndex::ALL => count($matched)];
        foreach ($matched as $store) {
            $bucket = AlphabetIndex::letterFor($store->name);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        $visible = $letter === AlphabetIndex::ALL ? $matched : array_values(array_filter(
            $matched,
            static fn(AgentStore $s): bool => AlphabetIndex::letterFor($s->name) === $letter,
        ));

        $pageCount = max(1, (int) ceil(count($visible) / self::PER_PAGE));
        $page = min($page, $pageCount);
        $pageItems = array_slice($visible, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(__DIR__ . '/template', [
                'stores' => $pageItems,
                'counts' => $counts,
                'letter' => $letter,
                'search' => $search,
                'page' => $page,
                'pageCount' => $pageCount,
                'totalMatched' => count($visible),
                'totalAvailable' => count($available),
            ]);
    }

    private static function matches(AgentStore $store, string $needle): bool
    {
        foreach ([$store->name, $store->company, $store->city, $store->address] as $field) {
            if ($field !== null && $field !== '' && mb_stripos($field, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
