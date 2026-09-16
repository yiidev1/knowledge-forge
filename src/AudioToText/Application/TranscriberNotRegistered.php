<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use LogicException;

/**
 * A provider exists in the enum but no engine was wired for it.
 *
 * A `LogicException`, not an `AudioTranscriptionException`, because it is not a condition an operator
 * can be told about in a sentence on a page — it means `config/common/di/audio-to-text.php` and
 * {@see \App\AudioToText\Domain\TranscriptionProvider} have drifted apart, and the fix is a code change.
 * A unit test asserts every case resolves, so this should never be reachable in a deployed build.
 */
final class TranscriberNotRegistered extends LogicException {}
