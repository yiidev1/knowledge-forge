<?php

declare(strict_types=1);

namespace App\Shared\Web\Middleware;

use App\Shared\Application\Correlation\CorrelationId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exposes the request's correlation id on the response as `X-Correlation-Id`.
 *
 * The id is already stamped on every log record (see {@see \App\Shared\Infrastructure\Log\SafeLogContext});
 * echoing it in the response lets an operator tie a user-reported error to the exact log lines without
 * exposing anything sensitive — the id is a random per-request token, not a secret.
 */
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorrelationId $correlationId,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Correlation-Id', $this->correlationId->value());
    }
}
