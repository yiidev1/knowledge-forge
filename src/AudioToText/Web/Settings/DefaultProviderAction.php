<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Settings;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function implode;
use function sprintf;

/**
 * Changes the global default transcription provider
 * (POST /audio-to-text/settings/default-provider).
 *
 * The form lives on `/admin/order58/store-audio`, which is where an administrator is already looking at
 * store audio, but the setting belongs to this module and so does its validation. Order58 posts here by
 * route name — a name is not a namespace, so module isolation holds — and this action redirects back to
 * that page afterwards.
 *
 * ## A default, not a migration
 *
 * Changing this affects **the next upload and nothing else**. Jobs already queued carry their own
 * provider, chosen when they were queued, and this action does not touch a single job row.
 *
 * ## Refusing an unconfigured provider
 *
 * A provider whose local configuration is incomplete cannot be made the default: it would preselect
 * itself on every upload form and fail every job queued from one. The check is
 * {@see AudioToTextSettings::providerIsUsable()} — **local configuration only**, no call to any
 * provider's API. A key this server holds but the provider rejects is a job-time failure, not something
 * a settings form can discover, and pretending otherwise would put a network round trip behind a
 * button press.
 */
final readonly class DefaultProviderAction
{
    public function __construct(
        private AudioToTextSettingsRepositoryInterface $settingsRepository,
        private AudioToTextSettings $settings,
        private CurrentAdmin $currentAdmin,
        private FlashMessages $flash,
        private Redirect $redirect,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $posted = FormData::fromRequest($request)->string('transcription_provider');
        $provider = TranscriptionProvider::fromStorage($posted === '' ? null : $posted);

        // `fromStorage` returns null rather than defaulting, precisely so this case is reachable. A
        // silent fallback to Whisper would report success for a value nobody asked for.
        if ($provider === null) {
            $this->flash->error('That is not a transcription provider this server knows about. Nothing was changed.');

            return $this->redirect->afterPost('order58.store-audio');
        }

        if (!$this->settings->providerIsUsable($provider)) {
            $problems = $this->settings->providerProblems()[$provider->label()] ?? [];

            $this->flash->error(sprintf(
                '%s is not configured on this server yet, so it cannot be made the default. %s',
                $provider->label(),
                implode(' ', $problems),
            ));

            return $this->redirect->afterPost('order58.store-audio');
        }

        $this->settingsRepository->saveDefaultProvider($provider, $this->currentAdmin->get()->id());

        $this->flash->success(sprintf(
            'New uploads will now use %s by default. Recordings already queued are unaffected.',
            $provider->label(),
        ));

        return $this->redirect->afterPost('order58.store-audio');
    }
}
