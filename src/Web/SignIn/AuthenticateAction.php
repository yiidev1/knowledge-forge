<?php

declare(strict_types=1);

namespace App\Web\SignIn;

use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function ceil;
use function is_array;
use function is_string;
use function sprintf;
use function trim;

/**
 * `POST /login` — signs in an administrator, an agent or an operation user from the one form.
 *
 * The form carries no role, and nothing posted here could choose one: where a person lands is decided by
 * which account their password verified against ({@see SignInService}). The messages are the ones both
 * login pages already used, so an existing user reads nothing new. CSRF is checked by the global
 * middleware before this runs.
 *
 * Every outcome redirects back to a fixed route name, never to anything taken from the request, so there is
 * no return address to turn into an open redirect.
 */
final readonly class AuthenticateAction
{
    public function __construct(
        private SignInService $signIn,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        [$username, $password] = $this->readCredentials($request);

        if ($username === '' || $password === '') {
            $this->flash->error('Enter your username and password.');

            return $this->redirect->afterPost('auth.login.show');
        }

        $result = $this->signIn->signIn($username, $password, $this->clientIp($request));

        if ($result->isSuccess()) {
            return $this->redirect->afterPost((string) $result->homeRoute);
        }

        if ($result->isLocked()) {
            $minutes = (int) ceil(($result->retryAfterSeconds ?? 0) / 60);
            $this->flash->error(
                sprintf('Too many failed attempts. Try again in %d minute%s.', $minutes, $minutes === 1 ? '' : 's'),
            );

            return $this->redirect->afterPost('auth.login.show');
        }

        if ($result->unavailable) {
            // Present only when an upstream service supplied a sentence worth showing; it arrives already
            // sanitized and is escaped again by the flash partial.
            $this->flash->error($result->message ?? 'Sign-in is temporarily unavailable. Please try again in a moment.');

            return $this->redirect->afterPost('auth.login.show');
        }

        // One message whichever account was tried, so the page never says which usernames exist where.
        $this->flash->error('Invalid username or password.');

        return $this->redirect->afterPost('auth.login.show');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readCredentials(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $username = isset($data['username']) && is_string($data['username']) ? trim($data['username']) : '';
        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';

        return [$username, $password];
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        return isset($params['REMOTE_ADDR']) && is_string($params['REMOTE_ADDR']) ? $params['REMOTE_ADDR'] : '';
    }
}
