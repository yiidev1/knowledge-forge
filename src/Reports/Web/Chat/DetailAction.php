<?php

declare(strict_types=1);

namespace App\Reports\Web\Chat;

use App\Reports\Contract\ChatReportReaderInterface;
use App\Reports\Domain\ChatReportRow;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\ScoreDisplay;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_string;
use function json_encode;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * The report's read-only detail feed as JSON (GET /admin/reports/chat/detail).
 *
 * Two modes, both driven by the *same* filters the page itself uses:
 *
 * - **List** — the records behind a metric. The browser never says "give me the low ratings"; it passes the
 *   ordinary report filters (`agent=…&rating=low`), which is why a drill-down link also works as a plain
 *   page URL with JavaScript off, and why the count on the card and the rows in the dialog cannot disagree.
 * - **Detail** — one question and its current answer, when `question` is given.
 *
 * Admin-only by virtue of the route group, and read-only: there is no write path here, and the response is
 * a fixed allow-list. No OpenAI file id, vector store id, storage path, token or hash is ever serialised —
 * citations are reduced to a count. The payload is `JSON_HEX_TAG`-encoded so a question or answer body is
 * inert if it ever lands somewhere HTML is parsed.
 */
final readonly class DetailAction
{
    public function __construct(
        private ChatReportReaderInterface $reader,
        private ChatReportRequest $request,
        private AppTimeZone $appTimeZone,
        private ClockInterface $clock,
        private ResponseFactoryInterface $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $now = $this->clock->now();

        $questionId = $this->positiveInt($params, 'question');
        if ($questionId !== null) {
            $query = $this->request->build($params, $now);
            $row = $this->reader->findDetail($questionId, $query);

            return $row === null
                ? $this->json(['error' => 'not_found'], 404)
                : $this->json(['detail' => $this->detail($row)]);
        }

        // List mode pages inside the dialog with its own parameter, so it never disturbs the page's tables.
        $params['qa_page'] = (string) ($this->positiveInt($params, 'dpage') ?? 1);
        $query = $this->request->build($params, $now, ChatReportRequest::DRILLDOWN_PER_PAGE);
        $result = $this->reader->list($query);

        $rows = [];
        foreach ($result->items as $row) {
            $rows[] = $this->summaryRow($row);
        }

        return $this->json([
            'total' => $result->total,
            'page' => $result->currentPage(),
            'page_count' => $result->pageCount(),
            'rows' => $rows,
        ]);
    }

    /**
     * The compact shape the dialog's list uses. Deliberately without question/answer bodies — the list is an
     * index, and a body is fetched only when one record is opened.
     *
     * @return array<string, mixed>
     */
    private function summaryRow(ChatReportRow $row): array
    {
        return [
            'question_id' => $row->questionId,
            'asked_at' => $this->appTimeZone->format($row->askedAt, 'M j, Y g:i A'),
            'agent' => $row->agentLabel(),
            'chat_type' => $row->chatType === ChatTypeFilter::Rule ? 'Rule Chat' : 'Knowledge',
            'store' => $row->chatType === ChatTypeFilter::Rule ? '—' : ($row->storeName ?? '—'),
            'rating' => $this->ratingLabel($row),
            'status' => $this->statusLabel($row),
            'response' => $row->responseSeconds === null
                ? '—'
                : ScoreDisplay::duration($row->responseSeconds),
            'has_comment' => $row->comment !== null,
        ];
    }

    /**
     * The detail pane deliberately shows only the rating, the question and the answer — plus a feedback
     * comment when one exists. Everything else (agent, store, status, response time) is already on the row
     * that opened the dialog, so it is not sent here either: the payload is what the page renders, nothing
     * more.
     *
     * @return array<string, mixed>
     */
    private function detail(ChatReportRow $row): array
    {
        return [
            'question_id' => $row->questionId,
            'rating' => $this->ratingLabel($row),
            'question' => $row->question,
            // Never invented: an unanswered question sends null and the dialog says so.
            'answer' => $row->answer,
            'comment' => $row->comment,
        ];
    }

    /** A dismissal is reported as declined, never as a zero. */
    private function ratingLabel(ChatReportRow $row): string
    {
        if ($row->score !== null) {
            return ScoreDisplay::label($row->score);
        }

        if ($row->dismissed) {
            return 'Declined';
        }

        return $row->isAnswered() ? 'Unrated' : '—';
    }

    private function statusLabel(ChatReportRow $row): string
    {
        if (!$row->isAnswered()) {
            return 'No active answer';
        }

        return $row->isGrounded ? 'Grounded' : 'Fallback';
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function positiveInt(array $params, string $key): ?int
    {
        $value = $params[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Chat content should not sit in a shared or browser cache after sign-out.
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
