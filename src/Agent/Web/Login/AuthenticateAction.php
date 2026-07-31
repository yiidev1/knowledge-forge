<?php

declare(strict_types=1);

namespace App\Agent\Web\Login;

use App\Agent\Application\AgentLoginService;
use App\Auth\Application\ThrottleKey;
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
 * Processes an agent login submission (POST /agent/login). Post/redirect/get throughout; the failure
 * message is generic (no account enumeration). The throttle key is namespaced so agent and admin login
 * attempts never share a bucket even for the same username and IP.
 */
final readonly class AuthenticateAction
{
    private const THROTTLE_NAMESPACE = 'order58-agent';

    public function __construct(
        private AgentLoginService $loginService,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        [$username, $password] = $this->readCredentials($request);

        if ($username === '' || $password === '') {
            $this->flash->error('Enter your username and password.');

            return $this->redirect->afterPost('agent.login.show');
        }

        $throttleKey = ThrottleKey::for(self::THROTTLE_NAMESPACE . ':' . $username, $this->clientIp($request));
        $result = $this->loginService->login($username, $password, $throttleKey);

        if ($result->success) {
            return $this->redirect->afterPost('agent.home');
        }

        if ($result->locked) {
            $minutes = (int) ceil(($result->retryAfterSeconds ?? 0) / 60);
            $this->flash->error(
                sprintf('Too many failed attempts. Try again in %d minute%s.', $minutes, $minutes === 1 ? '' : 's'),
            );

            return $this->redirect->afterPost('agent.login.show');
        }

        if ($result->unavailable) {
            $this->flash->error('Sign-in is temporarily unavailable. Please try again in a moment.');

            return $this->redirect->afterPost('agent.login.show');
        }

        $this->flash->error('Invalid username or password.');

        return $this->redirect->afterPost('agent.login.show');
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
