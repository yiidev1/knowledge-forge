<?php

declare(strict_types=1);

namespace App\Shared\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\UrlGeneratorInterface;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * Makes the application work when it is mounted on a URL prefix instead of the domain root.
 *
 * The routes in `config/common/routes.php` are written against the domain root (`/`, `/login`,
 * `/knowledge-bases`). A front end that serves the application from `https://example.com/prefix/`
 * still hands PHP the full request path, so without this middleware every route misses and the
 * application answers 404 for URLs that are in fact correct.
 *
 * Two symmetrical halves, both required — one alone leaves the application half-broken:
 *
 * 1. Incoming: the prefix is removed from the request path, so `/prefix/login` is routed as `/login`.
 *    Only the path is rewritten; the query string, method, headers and body are untouched.
 * 2. Outgoing: the same prefix is handed to the URL generator, so every `generate()` call — links,
 *    form actions, redirects, pagination — comes back out carrying it again.
 *
 * Asset URLs do not pass through the URL generator; they resolve through the `@baseUrl` alias, which
 * `config/common/aliases.php` derives from the same environment variable.
 *
 * With no prefix configured the middleware is inert, which keeps a root deployment on exactly the code
 * path it had before this class existed.
 */
final readonly class BasePathMiddleware implements MiddlewareInterface
{
    /**
     * @param string $basePath Normalised by {@see \App\Environment::appBasePath()}: either empty or
     * a path that starts with `/` and does not end with one.
     */
    public function __construct(
        private string $basePath,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->basePath === '') {
            return $handler->handle($request);
        }

        // Set unconditionally, before the path check below: a request that arrives without the prefix
        // (a health probe hitting PHP-FPM directly, for instance) must still render links that work in
        // a browser, which means links that carry the prefix.
        $this->urlGenerator->setUriPrefix($this->basePath);

        $uri = $request->getUri();
        $path = $uri->getPath();

        if ($path === $this->basePath) {
            // `/prefix` with no trailing slash. The front end normally redirects this to `/prefix/`,
            // but handling it here keeps the application correct on its own.
            $path = '/';
        } elseif (str_starts_with($path, $this->basePath . '/')) {
            $path = substr($path, strlen($this->basePath));

            // The front controller is reachable by name — `/prefix/index.php` is the URL the web
            // server maps to this script. There is no route by that name, so route it as the root the
            // same way the PHP built-in server does, rather than answering 404 for the application's
            // own entry point.
            if ($path === '/index.php') {
                $path = '/';
            }
        } else {
            // Outside the mount point. Pass it through unchanged and let the router answer, rather
            // than mangling a path this middleware has no claim on.
            return $handler->handle($request);
        }

        return $handler->handle($request->withUri($uri->withPath($path), true));
    }
}
