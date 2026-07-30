<?php

declare(strict_types=1);

namespace App\Shared\Web\Middleware;

use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Http\Status;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Maps expected domain failures to safe HTTP responses.
 *
 * A {@see NotFoundException} — raised whenever a slug or scoped id does not resolve — becomes a plain
 * 404 rendered with the standard not-found page. This keeps "does not exist" and "belongs to someone
 * else" indistinguishable, and keeps internal identifiers and stack traces out of the response.
 * Unexpected exceptions are left to propagate to the framework error handler.
 */
final readonly class DomainExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private Aliases $aliases,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (NotFoundException) {
            // Aliases are resolved to an absolute path here: WebViewRenderer::render() does not itself
            // resolve "@"-prefixed view names (only the layout path is alias-resolved).
            return $this->viewRenderer
                ->withLayout($this->aliases->get('@src/Web/Shared/Layout/Admin/layout.php'))
                ->render($this->aliases->get('@src/Web/Shared/Error/not-found'))
                ->withStatus(Status::NOT_FOUND);
        }
    }
}
