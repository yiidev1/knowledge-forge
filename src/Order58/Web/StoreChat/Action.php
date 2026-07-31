<?php

declare(strict_types=1);

namespace App\Order58\Web\StoreChat;

use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Shared\Web\Support\AlphabetIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function min;
use function trim;

/**
 * The admin "Store chat" picker (GET /admin/order58/store-chat): an alphabetical, searchable list of the
 * stores that can actually be chatted with — vector store ready and holding at least one enabled, ready
 * document. Choosing one opens that store's chat. Same directory machinery as the store list, restricted to
 * chat-ready stores.
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
        $rawSearch = $params['q'] ?? null;
        $rawLetter = $params['letter'] ?? null;
        $rawPage = $params['page'] ?? null;

        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $letter = AlphabetIndex::normalize(is_string($rawLetter) ? $rawLetter : null);
        $page = is_string($rawPage) ? max(1, (int) $rawPage) : 1;

        $query = new StoreDirectoryQuery($search, StoreDirectoryFilter::All, $letter, $page, self::PER_PAGE);
        $result = $this->reader->search($query);

        if ($page > $result->pageCount()) {
            $query = new StoreDirectoryQuery($search, StoreDirectoryFilter::All, $letter, $result->pageCount(), self::PER_PAGE);
            $result = $this->reader->search($query);
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'result' => $result,
                'search' => $search,
                'letter' => $letter,
                'page' => min($page, $result->pageCount()),
            ]);
    }
}
