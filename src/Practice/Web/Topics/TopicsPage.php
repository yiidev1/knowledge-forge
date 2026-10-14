<?php

declare(strict_types=1);

namespace App\Practice\Web\Topics;

use App\Practice\Domain\Topic;
use App\Practice\Domain\TopicRepositoryInterface;
use App\Practice\Domain\TopicStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function ceil;
use function is_string;
use function max;
use function trim;

/**
 * Renders the topic list, with or without its dialog open.
 *
 * Shared by the index action and by both mutation actions, because a submission that fails validation
 * must come back to the *same page* with the dialog still open, the typed values still in it and the
 * message under the field it belongs to. Redirecting instead would lose what was typed, and a second
 * copy of the listing code would be a second place for the filters to drift.
 */
final readonly class TopicsPage
{
    public const PER_PAGE = 20;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private TopicRepositoryInterface $topics,
    ) {}

    /**
     * @param array<string, string> $values what was typed, when a submission bounced
     * @param array<string, string> $errors keyed by form field name
     */
    public function render(
        ServerRequestInterface $request,
        bool $creating = false,
        ?Topic $editing = null,
        array $values = [],
        array $errors = [],
    ): ResponseInterface {
        $params = $request->getQueryParams();

        $search = is_string($params['q'] ?? null) ? trim((string) $params['q']) : '';
        /** @var mixed $rawStatus */
        $rawStatus = $params['status'] ?? null;
        $status = TopicStatus::fromRequest(is_string($rawStatus) ? $rawStatus : null);

        // A deleted topic is not something this screen curates: it is gone from the list, and the
        // orders that kept it show it on their own rows. So the stored delete value is not a filter
        // anybody can ask for here, by chip or by hand-edited URL — it falls back to the working list.
        if ($status === TopicStatus::Archived) {
            $status = null;
        }
        $page = is_string($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;

        $result = $this->topics->page($search, $status, $page, self::PER_PAGE);
        $pageCount = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        // A stale bookmark past the end lands on the last page of rows, not on an empty one.
        if ($page > $pageCount) {
            $page = $pageCount;
            $result = $this->topics->page($search, $status, $page, self::PER_PAGE);
        }

        // The dialog may also be asked for by the URL, which is what makes "Add topic" a real link.
        if (!$creating && $editing === null && $errors === []) {
            $requested = is_string($params['topic'] ?? null) ? $params['topic'] : null;

            if ($requested === 'new') {
                $creating = true;
            } elseif ($requested !== null && $requested !== '') {
                $found = $this->topics->find((int) $requested);
                // A deleted topic has no row on this page and no Edit link; a stale URL naming one
                // opens nothing rather than a form that would write to it.
                $editing = $found !== null && !$found->isArchived() ? $found : null;
            }
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'topics' => $result['items'],
                'total' => $result['total'],
                'page' => $page,
                'pageCount' => $pageCount,
                'search' => $search,
                'status' => $status,
                'creating' => $creating,
                'editing' => $editing,
                'values' => $values,
                'errors' => $errors,
            ]);
    }
}
