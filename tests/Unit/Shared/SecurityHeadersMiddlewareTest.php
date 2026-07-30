<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Web\Middleware\SecurityHeadersMiddleware;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The security-headers middleware stamps every response, and emits HSTS only over HTTPS.
 */
final class SecurityHeadersMiddlewareTest extends Unit
{
    public function testSetsTheStandardSecurityHeaders(): void
    {
        $response = $this->handle('http://localhost/login');

        assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        assertStringContainsString("object-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testHstsIsAbsentOverPlainHttp(): void
    {
        assertFalse($this->handle('http://localhost/login')->hasHeader('Strict-Transport-Security'));
    }

    public function testHstsIsPresentOverHttps(): void
    {
        $response = $this->handle('https://localhost/login');

        assertTrue($response->hasHeader('Strict-Transport-Security'));
        assertStringContainsString('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
    }

    private function handle(string $uri): ResponseInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        return (new SecurityHeadersMiddleware())->process(new ServerRequest('GET', $uri), $handler);
    }
}
