<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use function sprintf;

/**
 * A file presented as an image is not a decodable raster, or exceeds the configured dimension limits.
 *
 * Rejecting undecodable images matters for defence in depth: a file whose MIME sniffs as an image but
 * which the image decoder cannot parse should never be stored or sent onward.
 */
final class InvalidImage extends UploadException
{
    public function errorCode(): string
    {
        return 'invalid_image';
    }

    public static function undecodable(): self
    {
        return new self('This image could not be read. It may be corrupt or not a real image.');
    }

    public static function tooLarge(int $width, int $height, int $maxWidth, int $maxHeight): self
    {
        return new self(
            sprintf(
                'This image is %d×%d pixels, larger than the %d×%d limit.',
                $width,
                $height,
                $maxWidth,
                $maxHeight,
            ),
        );
    }
}
