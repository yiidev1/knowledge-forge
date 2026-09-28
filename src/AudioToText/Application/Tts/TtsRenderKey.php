<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Application\Settings\TtsSettings;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsVoice;

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
 * ## Why the chunk size and the gap are in here
 *
 * Deepgram reads each request as a self-contained piece of text, so where the splits fall changes the
 * phrasing and the pauses. Two files built from identical words with different `DEEPGRAM_TTS_MAX_CHARS`
 * genuinely do not sound the same, which makes it a render input rather than an implementation detail.
 *
 * The inter-turn gap is here for the same reason and not in {@see TtsSourceDigest}: lengthening the
 * breath between two turns changes the file without changing one word of what is said, so it is a render
 * setting. Putting it in the digest would announce a transcript change that never happened.
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
    public static function for(
        TtsSettings $settings,
        TtsOutputType $outputType,
        ?TtsVoice $voice = null,
    ): string {
        $parts = [
            'v1',
            $settings->outputFormat->value,
            sprintf('%dhz', $settings->sampleRate),
            sprintf('max%d', $settings->maxCharactersPerRequest),
        ];

        if ($voice !== null) {
            // One voice throughout, so the other three settings cannot invalidate this file.
            $parts[] = 'voice=' . $settings->modelForVoice($voice);
        } elseif ($outputType === TtsOutputType::Mixed) {
            $parts[] = 'c=' . $settings->customerModel;
            $parts[] = 'a=' . $settings->agentModel;
        } else {
            $parts[] = ($outputType === TtsOutputType::Agent ? 'a=' : 'c=')
                . $settings->modelFor($outputType === TtsOutputType::Agent);
        }

        // Last, and on every output.
        //
        // It used to be on the two branches above and not on the third, because the assembler only put
        // gaps in a mixed file. It now puts one between any two turns whatever the output is, so the
        // setting can change how a per-role file sounds too — and a render input that is missing from
        // the key is audio the page calls current while it no longer matches the configuration.
        //
        // The one-time cost of adding it here is that existing per-role renditions read as "generated
        // with a different voice setting" once. That is true of them: generated again today, a
        // multi-turn one would come out with breaths it does not have.
        $parts[] = sprintf('gap%d', $settings->gapMilliseconds);

        return implode('|', $parts);
    }
}
