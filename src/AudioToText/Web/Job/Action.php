<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\Shared\Application\Time\AppTimeZone;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * One job's detail page.
 *
 * Authorization is the route middleware plus existence — every authorized administrator may view every
 * job. A missing id yields the shared 404 rather than a 403, so an id that does not exist and one that
 * does are indistinguishable from outside.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private AudioToTextSettings $settings,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'job' => $job,
                // The view decides how much may be claimed about each speaker, from the separation
                // status rather than from the roles stored on the utterances. The template receives
                // labels it can print verbatim and makes no judgement of its own.
                'conversation' => ConversationView::from(
                    $job->speakerSeparationStatus,
                    $this->decodeSegments($job->speakerSegmentsJson),
                    $job->speakerRoleConfidence,
                    $job->hasSeparatedText(),
                ),
                // Only meaningful while the job is still waiting; null otherwise.
                'queuePosition' => $this->jobs->queuePositionOf($job->id),
                'pollSeconds' => $this->settings->transcription->pollSeconds(),
                'appTimeZone' => $this->appTimeZone,
            ])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Rebuilds the speaker-labelled conversation from the stored JSON.
     *
     * Best effort throughout: this powers a review panel, and a column that will not decode should cost
     * that panel and nothing else. The transcript above it is a separate column and is unaffected.
     *
     * @return list<SpeakerUtterance>
     */
    private function decodeSegments(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $utterances = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $text = $row['text'] ?? null;
            $speaker = $row['speaker'] ?? null;
            $role = $row['role'] ?? null;
            $startMs = $row['start_ms'] ?? null;
            $endMs = $row['end_ms'] ?? null;
            $confidence = $row['confidence'] ?? null;

            if (!is_string($text) || !is_string($speaker)) {
                continue;
            }

            $utterances[] = new SpeakerUtterance(
                is_int($startMs) ? $startMs : 0,
                is_int($endMs) ? $endMs : 0,
                $speaker,
                SpeakerRole::fromStorage(is_string($role) ? $role : null),
                $text,
                is_numeric($confidence) ? (float) $confidence : 0.0,
            );
        }

        return $utterances;
    }
}
