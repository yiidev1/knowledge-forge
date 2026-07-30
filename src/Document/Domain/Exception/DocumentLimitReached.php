<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use function sprintf;

final class DocumentLimitReached extends UploadException
{
    public function errorCode(): string
    {
        return 'document_limit_reached';
    }

    public static function limit(int $limit): self
    {
        return new self(
            sprintf('This knowledge base has reached its limit of %d documents. Remove some to add more.', $limit),
        );
    }
}
