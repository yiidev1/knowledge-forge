<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Shared;

use Yiisoft\Session\SessionInterface;

/**
 * A session that records the order of the calls made against it.
 *
 * The point is ordering, not storage: the fix for the blocking second tab depends on `close()` happening
 * *before* the long provider call, and the only way to assert that in a unit test is to interleave the
 * session's calls with the provider's on one timeline. `$events` is that timeline.
 */
final class RecordingSession implements SessionInterface
{
    /** @var list<string> */
    public array $events = [];

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $active = false;
    private ?string $id = 'session-id';

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->open();
        $this->data[$key] = $value;
    }

    public function close(): void
    {
        $this->events[] = 'session.close';
        $this->active = false;
    }

    public function open(): void
    {
        if (!$this->active) {
            $this->events[] = 'session.open';
            $this->active = true;
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $sessionId): void
    {
        $this->id = $sessionId;
    }

    public function regenerateId(): void
    {
        $this->events[] = 'session.regenerateId';
    }

    public function discard(): void
    {
        $this->events[] = 'session.discard';
        $this->active = false;
    }

    public function destroy(): void
    {
        $this->events[] = 'session.destroy';
        $this->active = false;
        $this->id = null;
    }

    public function getName(): string
    {
        return 'KFSESSID';
    }

    public function all(): array
    {
        return $this->data;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function pull(string $key, $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function getCookieParameters(): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
