<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Conversion\AiAudio;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * The three-column layout for one conversion's result page, and nothing else.
 *
 * ## Why a bundle of its own
 *
 * This is the only page that lays its content out in columns, and the store's upload page — which is
 * finished and must not move — shares the stylesheet every admin screen loads. A bundle registered by
 * one template cannot reach a page that does not register it, which makes "this changes that page only"
 * a fact about the build rather than a promise about selectors.
 *
 * Every class in it is prefixed `a2t-conv-`, used by this template alone, so even the global stylesheet
 * could not collide with it.
 *
 * No JavaScript. The columns are a grid, the conversation scrolls because a container says `overflow-y`,
 * and the players are native `<audio controls>` exactly as they were.
 */
final class ConversionAudioAsset extends AssetBundle
{
    public ?string $basePath = '@assets/conversion-audio';
    public ?string $baseUrl = '@assetsUrl/conversion-audio';
    public ?string $sourcePath = '@assetsSource/conversion-audio';

    /** For the card, badge, button and bubble styles this layout arranges but never redefines. */
    public array $depends = [AdminAsset::class];
    public array $css = ['conversion-audio.css'];
}
