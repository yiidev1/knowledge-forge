<?php

declare(strict_types=1);

namespace App\Agent\Web\Login;

use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;

/**
 * `GET /agent/login` — now the one sign-in page's old address.
 *
 * Agents and operation users sign in at `/login` with administrators. This address keeps working for every
 * bookmark, every gate that still sends a signed-out agent here, and the sign-out redirect: each lands on
 * the same form. A flash message set before the redirect survives it, because nothing renders in between.
 */
final readonly class ShowAction
{
    public function __construct(
        private Redirect $redirect,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->redirect->toRoute('auth.login.show');
    }
}
