<?php

declare(strict_types=1);

namespace App\Shared\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the application's security response headers to every response, including error responses.
 *
 * The app is authoritative for these headers so the policy holds behind any front end — nginx, the
 * built-in dev server, or none — rather than living only in one vhost file that a new deployment might
 * forget. The CSP keeps all scripts and styles same-origin (no inline handlers anywhere), which is why
 * the one stylesheet and script are external assets.
 *
 * HSTS is emitted only over HTTPS: sending it on a plain-HTTP dev request would wrongly pin the browser
 * to HTTPS for a host that does not serve it.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "img-src 'self' data:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'";

    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=()';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', self::PERMISSIONS_POLICY);

        if ($request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
