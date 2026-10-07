<?php

declare(strict_types=1);

namespace App\AudioToText\Web\OrderTesting;

use App\AudioToText\Web\Job\Store\StoreAudioAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * Order Testing's own stylesheet and script, on top of the store page's proven ones.
 *
 * ## Why it depends on the audio bundle rather than copying it
 *
 * `audio-store.js` is several thousand lines driving uploads, modals, polling, review, replacement and
 * generated audio, all bound to `data-a2t-*` attributes the template renders. Copying it to rename those
 * attributes would duplicate every one of those behaviours and guarantee the two copies drift — a bug
 * fixed on one page staying broken on the other. The two surfaces are never on screen at the same time,
 * so one script binding to one page's attributes cannot collide with the other.
 *
 * What Order Testing owns is its **template**, which decides what is on the page and which URLs the
 * attributes carry, and the two files here, which are where behaviour and styling of its own go. That is
 * the boundary that matters: changing this surface's presentation cannot change the audio page's.
 *
 * When Order Testing's behaviour genuinely diverges, `order-testing.js` is where it goes. Forking the
 * shared script is a decision to take then, against a real requirement, rather than now against none.
 */
final class OrderTestingAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order-testing';

    public ?string $baseUrl = '@assetsUrl/order-testing';

    public ?string $sourcePath = '@assetsSource/order-testing';

    public array $depends = [StoreAudioAsset::class];

    public array $css = ['order-testing.css'];

    public array $js = ['order-testing.js'];
}
