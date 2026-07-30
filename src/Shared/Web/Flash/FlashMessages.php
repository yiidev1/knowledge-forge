<?php

declare(strict_types=1);

namespace App\Shared\Web\Flash;

use Yiisoft\Session\Flash\FlashInterface;

use function is_array;
use function is_string;

/**
 * Typed, one-shot notifications shown after a redirect.
 *
 * Wraps the framework flash bag so actions add messages by intent (success / error / warning / info)
 * and the layout renders them uniformly. Messages survive exactly one request — the redirect that
 * follows a POST — which is what makes post/redirect/get able to report an outcome without risking a
 * re-submission on refresh.
 */
final readonly class FlashMessages
{
    private const KEY = 'kf.flash';

    public function __construct(
        private FlashInterface $flash,
    ) {}

    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    public function error(string $message): void
    {
        $this->add('error', $message);
    }

    public function warning(string $message): void
    {
        $this->add('warning', $message);
    }

    public function info(string $message): void
    {
        $this->add('info', $message);
    }

    /**
     * @return list<array{level: string, message: string}>
     */
    public function consume(): array
    {
        /** @var mixed $raw */
        $raw = $this->flash->get(self::KEY);

        if (!is_array($raw)) {
            return [];
        }

        $messages = [];

        /** @var mixed $entry */
        foreach ($raw as $entry) {
            if (is_array($entry) && isset($entry['level'], $entry['message'])
                && is_string($entry['level']) && is_string($entry['message'])) {
                $messages[] = ['level' => $entry['level'], 'message' => $entry['message']];
            }
        }

        return $messages;
    }

    private function add(string $level, string $message): void
    {
        $this->flash->add(self::KEY, ['level' => $level, 'message' => $message]);
    }
}
