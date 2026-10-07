<?php

declare(strict_types=1);

namespace App\AudioToText\Web\OrderTesting;

use App\AudioToText\Web\Job\Store\Group\RecordingsAction as AudioRecordingsAction;

/**
 * Manage Audio, read from the Order Testing surface.
 *
 * One line of difference: where a replacement is posted. Everything the dialog shows — the versions, the
 * refusal reasons, the provider options, the per-slot state — is the audio page's own implementation,
 * inherited rather than copied, because Manage Audio answers the same question on both surfaces and two
 * copies would be two chances to answer it differently.
 *
 * The replacement route has to differ: an operator who opened Order Testing must come back to Order
 * Testing, not be moved onto a page they never opened. {@see OrderTestingRoute} for why the group-scoped
 * endpoints belong to each surface while the job-scoped ones are shared.
 */
final readonly class RecordingsAction extends AudioRecordingsAction
{
    protected function replaceRoute(): string
    {
        return OrderTestingRoute::STORE_GROUP_REPLACE;
    }
}
