<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers with a fixture and keeps the request it was given.
 *
 * Exists so a test can assert the **final outgoing request** — the URI after every encoding step, the
 * headers as they would go on the wire — rather than the inputs that produced it. That distinction is
 * the whole point for Deepgram's repeated `keyterm=` parameters, where the wrong encoding is invisible
 * until the last step.
 *
 * A named class rather than an anonymous one because a caller needs to read `$request` back, and an
 * anonymous class cannot be named in a return type.
 */
final class RecordingHttpClient implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->response;
    }

    /** The captured query string, or an empty string if nothing was sent. */
    public function query(): string
    {
        return $this->request === null ? '' : (string) $this->request->getUri()->getQuery();
    }
}
