<?php

declare(strict_types=1);

namespace App\Document\Application\Validation;

use function getimagesize;
use function is_array;

/**
 * Verifies that a file claiming to be an image really is a decodable raster, and reads its dimensions.
 *
 * Uses core `getimagesize()`, which parses the image header — a file whose MIME sniffs as an image but
 * which cannot be decoded (corrupt, or a polyglot) fails here and is rejected before it is ever stored
 * or sent to the vision model. Dimensions are returned so the caller can enforce a pixel cap, which
 * bounds the memory the later base64/vision step will use.
 */
final class ImageInspector
{
    /**
     * @return array{width: int, height: int}|null Null when the file is not a decodable image.
     */
    public function inspect(string $absolutePath): ?array
    {
        $info = @getimagesize($absolutePath);

        if (!is_array($info) || !isset($info[0], $info[1]) || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }

        return ['width' => $info[0], 'height' => $info[1]];
    }
}
