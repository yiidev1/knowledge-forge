<?php

declare(strict_types=1);

namespace App\Shared\Web\Support;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Builds redirect responses to named routes.
 *
 * Centralised so actions never hand-assemble a `Location` header or hard-code a path, and so every
 * state-changing POST redirects with 303 (See Other) — which makes the browser follow with a GET and
 * prevents a refresh from re-submitting the form.
 */
final readonly class Redirect
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @param array<string, scalar|\Stringable|null> $arguments
     */
    public function toRoute(string $routeName, array $arguments = [], int $status = Status::FOUND): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Location', $this->urlGenerator->generate($routeName, $arguments));
    }

    /**
     * Redirect after a successful POST. 303 forces the browser to GET the target, so a page refresh
     * cannot replay the mutation.
     *
     * @param array<string, scalar|\Stringable|null> $arguments
     */
    public function afterPost(string $routeName, array $arguments = []): ResponseInterface
    {
        return $this->toRoute($routeName, $arguments, Status::SEE_OTHER);
    }
}
