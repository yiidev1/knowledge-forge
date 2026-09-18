<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Application\Settings\TtsSettings;
use App\AudioToText\Domain\Tts\TtsOutputType;

use function implode;
use function sprintf;

/**
 * Everything that changes the sound of a rendition without changing a word of it.
 *
 * Kept apart from {@see TtsSourceDigest} on purpose. Both questions look alike and mean different
 * things, and the page has to be able to say the right one:
 *
 * | what changed            | what the administrator is told                       |
 * |-------------------------|------------------------------------------------------|
 * | the transcript          | "Transcript changed after this audio was generated." |
 * | a voice or the format   | "Generated with a different voice setting."          |
 *
 * Folding the second into the first would make every `.env` edit announce itself as a transcript change.
 * That is false, and a notice that is sometimes false is a notice people stop reading — including on the
 * occasion it is reporting a real correction that never made it into the audio.
 *
 * Neither is ever acted on automatically. Both offer a button; the money is spent by a person.
 *
 * ## Why the chunk size is in here
 *
 * Deepgram reads each request as a self-contained piece of text, so where the splits fall changes the
 * phrasing and the pauses. Two files built from identical words with different `DEEPGRAM_TTS_MAX_CHARS`
 * genuinely do not sound the same, which makes it a render input rather than an implementation detail.
 *
 * Stored as a short readable string rather than a hash so that an operator reading the database row can
 * see what a file was made with, which is the only time anybody looks at this column.
 */
final class TtsRenderKey
{
    /**
     * @param TtsOutputType $outputType decides which voices are involved at all, so a single-role file
     *                                  is not invalidated by a change to the other role's voice
     */
    public static function for(TtsSettings $settings, TtsOutputType $outputType): string
    {
        $parts = [
            'v1',
            $settings->outputFormat->value,
            sprintf('%dhz', $settings->sampleRate),
            sprintf('max%d', $settings->maxCharactersPerRequest),
        ];

        if ($outputType === TtsOutputType::Mixed) {
            $parts[] = 'c=' . $settings->customerModel;
            $parts[] = 'a=' . $settings->agentModel;
            // Only a mixed file has gaps between turns, so only a mixed file is affected when that
            // setting moves. Including it everywhere would invalidate per-role audio for no reason.
            $parts[] = sprintf('gap%d', $settings->gapMilliseconds);
        } else {
            $parts[] = ($outputType === TtsOutputType::Agent ? 'a=' : 'c=')
                . $settings->modelFor($outputType === TtsOutputType::Agent);
        }

        return implode('|', $parts);
    }
}
