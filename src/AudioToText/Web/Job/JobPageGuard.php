<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The single 404 used by every job route.
 *
 * **404, never 403.** A 403 would confirm that an id exists, which is exactly what a 32-character
 * random public id is there to hide. "No such job" and "not available to you" have to be
 * indistinguishable from outside, or the id stops being unguessable in any useful sense.
 *
 * Note what this guard does *not* check: who uploaded the job. Authorization for this feature is
 * "authenticated administrator plus the job exists" — the route middleware supplies the first half and
 * the caller supplies the second. Every authorized administrator may view every job; the uploader is
 * recorded for audit only.
 */
final readonly class JobPageGuard
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function notFound(): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(404)
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
