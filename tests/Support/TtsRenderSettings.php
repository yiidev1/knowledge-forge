<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AudioToText\Application\Settings\TtsSettings;
use App\AudioToText\Domain\Tts\TtsOutputFormat;
use App\Shared\Domain\ValueObject\SecretValue;

use function dirname;

/**
 * The text-to-speech settings this machine is actually running with.
 *
 * Two tests need them for the same reason: a stored rendition carries the render key it was made with,
 * and the page calls audio "generated with a different voice setting" when that key no longer matches
 * what the settings would produce now. A test that wants a rendition the page reads as *current* must
 * therefore write the key the running configuration yields — any made-up value is a correct
 * `DifferentVoice`, which is a confusing way for a test about cost to fail.
 *
 * Built from `params.php` exactly as `config/common/di/audio-to-text.php` builds it, and in one place
 * rather than two: this was copied between an end-to-end script and a browser test before it was a file,
 * and a second copy is the one that gets left behind when a setting is added.
 *
 * Not a substitute for the container. It reads configuration and constructs one value object; nothing
 * here decides anything the application would decide differently.
 */
final class TtsRenderSettings
{
    public static function fromParams(): TtsSettings
    {
        /** @var array<string, array<string, mixed>> $params */
        $params = require dirname(__DIR__, 2) . '/config/common/params.php';
        $tts = $params['app/audio-deepgram-tts'];

        return new TtsSettings(
            apiKey: new SecretValue((string) $tts['apiKey']),
            url: (string) $tts['url'],
            customerModel: (string) $tts['customerModel'],
            agentModel: (string) $tts['agentModel'],
            callerModel: (string) $tts['callerModel'],
            calleeModel: (string) $tts['calleeModel'],
            sampleRate: (int) $tts['sampleRate'],
            maxCharactersPerRequest: (int) $tts['maxCharactersPerRequest'],
            timeoutSeconds: (int) $tts['timeoutSeconds'],
            gapMilliseconds: (int) $tts['gapMilliseconds'],
            outputFormat: TtsOutputFormat::fromConfig((string) $tts['outputFormat']),
            maxAttempts: (int) $tts['maxAttempts'],
        );
    }
}
