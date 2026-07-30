<?php

declare(strict_types=1);

namespace App\Auth\Web\Login;

use App\Auth\Application\LoginService;
use App\Auth\Application\ThrottleKey;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_array;
use function is_string;

/**
 * Processes a login submission (POST /login).
 *
 * Post/redirect/get throughout: whatever the outcome, the response is a redirect carrying a flash
 * message, so a browser refresh never re-submits credentials. The failure message is deliberately
 * generic — it never says whether the username exists.
 */
final readonly class AuthenticateAction
{
    public function __construct(
        private LoginService $loginService,
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

        $throttleKey = ThrottleKey::for($username, $this->clientIp($request));
        $result = $this->loginService->login($username, $password, $throttleKey);

        if ($result->isSuccess()) {
            return $this->redirect->afterPost('dashboard');
        }

        if ($result->isLocked()) {
            $minutes = (int) ceil(($result->retryAfterSeconds() ?? 0) / 60);
            $this->flash->error(
                sprintf('Too many failed attempts. Try again in %d minute%s.', $minutes, $minutes === 1 ? '' : 's'),
            );

            return $this->redirect->afterPost('auth.login.show');
        }

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
