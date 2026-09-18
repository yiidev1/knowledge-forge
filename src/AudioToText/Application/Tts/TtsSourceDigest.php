<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsUtterance;
use JsonException;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Identifies a transcript by its content, so generated audio can be pinned to the exact words it says.
 *
 * ## Why not `review_count`
 *
 * It is an optimistic-lock counter, not a version of the text. A REVERT increments it while restoring
 * byte-identical wording, and CONFIRM_ROLES increments it without touching a single word. Pinning audio
 * to it would raise "your transcript changed, regenerate?" on calls where nothing was said differently —
 * and clearing that notice costs real money, so the false positive is not free. A digest of the words
 * cannot be wrong in either direction.
 *
 * ## The framing is the part that has to be right
 *
 * The obvious implementation — `implode("\n", ["AGENT: …", "CUSTOMER: …"])` — is **not injective**. One
 * turn whose text happens to contain a newline followed by `CUSTOMER: ` produces exactly the same string
 * as two turns, so two genuinely different conversations hash the same and one of them silently keeps
 * the other's audio.
 *
 * `json_encode` over a list of tuples has no such hole: every string is escaped and delimited, so no
 * content can impersonate structure. That is the whole reason it is used here in preference to anything
 * cheaper.
 *
 * ## What is in, and what is deliberately out
 *
 * **In:** an algorithm version, the output type, and the ordered (role, text) pairs. The version tag is
 * there so that if this rule ever has to change, existing audio can be recognised as "made under the old
 * rule" rather than mistaken for current.
 *
 * **Out:** `review_count`, every timestamp, the `isReviewed` flag, and the voice models. The first three
 * for the reason above. The voices are excluded because a voice change does not change the words, and
 * saying "your transcript changed" when it has not would teach administrators to ignore the one notice
 * that matters. That question is answered separately by {@see TtsRenderKey}.
 *
 * The text hashed here has already been through {@see TtsSourceText::prepare()}, and the utterances are
 * the same objects that get sent — so the digest cannot describe text that was never spoken.
 * {@see TtsTextChunker} runs afterwards and preserves its input byte for byte, so chunking can never
 * move this value either.
 */
final class TtsSourceDigest
{
    /**
     * Bumped only if the framing or the preparation rule changes.
     *
     * A bump invalidates every stored `file_hash`, which presents as "transcript changed" on every
     * rendition and invites a paid regeneration of all of them — so it is a deliberate, costly act, not
     * a tidy-up.
     */
    private const VERSION = 'v1';

    /**
     * @param list<TtsUtterance> $utterances in the order they will be spoken
     */
    public static function for(TtsOutputType $outputType, array $utterances): string
    {
        $rows = [];

        foreach ($utterances as $utterance) {
            $rows[] = [$utterance->role->value, $utterance->text];
        }

        try {
            $canonical = json_encode(
                [self::VERSION, $outputType->value, $rows],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            // Unreachable in practice: malformed UTF-8 is the only way this throws, and every string here
            // has already been through TranscriptText::toValidUtf8(). It throws rather than returning a
            // placeholder because there is no safe placeholder — two renditions that both failed to hash
            // would compare equal, and every one of them would report itself as up to date.
            throw TtsException::digestFailed($e->getMessage());
        }

        return hash('sha256', $canonical);
    }
}
