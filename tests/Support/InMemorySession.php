<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Tests\Support;

use HeartPhrame\Session\SessionInterface;

use function bin2hex;
use function random_bytes;

/** HR: Minimalni server-side session test double. EN: Minimal server-side session test double. */
final class InMemorySession implements SessionInterface
{
    /** @var array<string,mixed> */
    private array $data = [];

    private bool $started = false;

    private string $id = 'test-session';

    public int $regenerations = 0;

    public function start(): bool
    {
        $this->started = true;

        return true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function getCsrfTokenName(): string
    {
        return '_csrf';
    }

    public function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(16));
        $this->set($this->getCsrfTokenName(), $token);

        return $token;
    }

    public function getCsrfToken(): ?string
    {
        $token = $this->get($this->getCsrfTokenName());

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function getOrGenerateCsrfToken(): string
    {
        return $this->getCsrfToken() ?? $this->generateCsrfToken();
    }

    public function validateCsrfToken(string $token): bool
    {
        return $token !== '' && hash_equals($this->getOrGenerateCsrfToken(), $token);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function regenerateId(bool $deleteOldSession = true): bool
    {
        ++$this->regenerations;
        $this->id = 'test-session-' . $this->regenerations;

        return true;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function close(): void
    {
        $this->started = false;
    }

    public function destroy(): bool
    {
        $this->clear();
        $this->started = false;

        return true;
    }

    public function getSessionTrackId(): ?string
    {
        return null;
    }

    public function getRequestTrackId(): ?string
    {
        return null;
    }
}
