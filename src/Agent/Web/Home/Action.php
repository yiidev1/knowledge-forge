<?php

declare(strict_types=1);

namespace App\Agent\Web\Home;

use App\Agent\Domain\AgentStore;
use App\Agent\Domain\AgentStoreDirectoryInterface;
use App\Shared\Web\Support\AlphabetIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_values;
use function is_string;
use function mb_stripos;
use function trim;

/**
 * The agent landing page (GET /agent): every active, ready store the agent can chat with, browsable by
 * name search and A–Z. The same list for every active agent — there is no per-agent store assignment and
 * `account_id` is never consulted. Search and letter filtering run over the (bounded) eligible set.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentStoreDirectoryInterface $directory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $rawSearch = $query['q'] ?? null;
        $rawLetter = $query['letter'] ?? null;
        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $letter = AlphabetIndex::normalize(is_string($rawLetter) ? $rawLetter : null);

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

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(__DIR__ . '/template', [
                'stores' => $visible,
                'counts' => $counts,
                'letter' => $letter,
                'search' => $search,
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
