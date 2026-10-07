<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Settings;

/**
 * The default transcription provider, saved from the Order Testing picker.
 *
 * One global setting, one row, one set of rules about which providers exist and which are configured —
 * all inherited. What differs is where the operator is returned to, because the form appears on two
 * pickers and sending someone to the one they were not looking at is a worse answer than a second route.
 *
 * The parent's own docblock makes the wider point: the form's page addresses this by **route name**, so
 * neither module names the other's namespace. That holds here too.
 */
final readonly class OrderTestingDefaultProviderAction extends DefaultProviderAction
{
    protected function returnTo(): string
    {
        return 'order-testing';
    }
}
