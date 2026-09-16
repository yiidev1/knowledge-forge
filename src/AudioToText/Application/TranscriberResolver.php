<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\Transcription\TranscriptionEngineInterface;
use App\AudioToText\Domain\TranscriptionProvider;

use function sprintf;

/**
 * Provider enum in, engine out.
 *
 * The **one** place a provider is turned into an implementation. That is the whole value of it: without
 * this, `if ($provider === TranscriptionProvider::Deepgram)` would appear in the orchestrator, then in
 * the worker's logging, then in a template deciding what to show — and each copy would be a place to
 * forget when a third engine arrives.
 *
 * The engines are injected as a keyed map rather than constructed here so the DI container keeps owning
 * object construction, and so a test can supply doubles for both without a container at all.
 */
final readonly class TranscriberResolver
{
    /**
     * @param array<string, TranscriptionEngineInterface> $engines keyed by provider value
     */
    public function __construct(
        private array $engines,
    ) {}

    /**
     * @param TranscriptionEngineInterface ...$engines each keyed by its own `provider()`
     */
    public static function of(TranscriptionEngineInterface ...$engines): self
    {
        $keyed = [];

        foreach ($engines as $engine) {
            $keyed[$engine->provider()->value] = $engine;
        }

        return new self($keyed);
    }

    /**
     * A missing engine is a wiring mistake, not a runtime condition.
     *
     * Every case of {@see TranscriptionProvider} must be registered in the DI file. Throwing a plain
     * exception rather than falling back to Whisper is deliberate: a silent fallback would transcribe
     * with the wrong engine and record it as the right one, which is worse than a failed job.
     */
    public function for(TranscriptionProvider $provider): TranscriptionEngineInterface
    {
        $engine = $this->engines[$provider->value] ?? null;

        if ($engine === null) {
            throw new TranscriberNotRegistered(
                sprintf('No transcription engine is registered for provider "%s".', $provider->value),
            );
        }

        return $engine;
    }

    /**
     * Which providers can actually run here, in enum order.
     *
     * Local configuration only — {@see TranscriptionEngineInterface::isAvailable()} may not open a
     * socket, so this is safe to call while rendering the upload form.
     *
     * @return list<TranscriptionProvider>
     */
    public function available(): array
    {
        $available = [];

        foreach (TranscriptionProvider::all() as $provider) {
            $engine = $this->engines[$provider->value] ?? null;

            if ($engine !== null && $engine->isAvailable()) {
                $available[] = $provider;
            }
        }

        return $available;
    }
}
