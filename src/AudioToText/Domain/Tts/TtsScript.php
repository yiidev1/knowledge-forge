<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

use function array_map;
use function array_sum;
use function count;
use function mb_strlen;

/**
 * Exactly what will be spoken, in order, plus what was left out and why that is visible.
 *
 * ## Why `omittedTurns` is carried rather than quietly discarded
 *
 * A mixed rendition can only voice turns that belong to the Agent or the Customer, because those are the
 * two voices there are. A diarized conversation can also contain turns mapped to `OTHER` — a third
 * person, a television, an automated greeting — and `UNKNOWN`, speech that could not be attributed to
 * anybody. There is no honest voice for either: picking one would put a confident label on an
 * attribution the rest of the application refuses to make, and `ConversationView` already declines to do
 * exactly that on screen.
 *
 * So they are skipped — and counted. A feature whose whole promise is "this is a clean reading of the
 * transcript" must not drop a line of it silently, so the page says how many turns were left out and the
 * administrator can reassign them on the existing Review screen if they matter. This is the same
 * boundary the stored `agent_text` and `customer_text` columns already draw; nothing new is being
 * decided here, it is only being reported.
 */
final readonly class TtsScript
{
    /**
     * @param list<TtsUtterance> $utterances
     * @param int                $omittedTurns turns skipped because no voice could honestly speak them
     */
    public function __construct(
        public array $utterances,
        public int $omittedTurns = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->utterances === [];
    }

    public function turnCount(): int
    {
        return count($this->utterances);
    }

    /**
     * Characters that will be billed.
     *
     * Counted here, before chunking, because chunking preserves its input exactly — so the sum of the
     * requests is this number however the splits happen to fall. Shown on the page before the button is
     * pressed, since "what will this cost" is a fair question to be able to answer in advance.
     */
    public function characterCount(): int
    {
        return array_sum(array_map(
            static fn(TtsUtterance $u): int => mb_strlen($u->text, 'UTF-8'),
            $this->utterances,
        ));
    }
}
