<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Socialite\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

/**
 * Minimal session store used by the OAuth flow to persist the CSRF `state`
 * and PKCE `code_verifier` between the redirect and callback legs.
 *
 * Backed by the kernel SessionPort whenever one is bound — the application's
 * own session, carried by its own cookie. That is the only store that works
 * everywhere: native PHP sessions are a second cookie the rest of the platform
 * knows nothing about, and under OpenSwoole (PHP_SAPI 'cli') `$_SESSION` is a
 * plain process global — shared by every request the worker serves and absent
 * from every other worker, so the callback leg either loses the state or reads
 * someone else's.
 *
 * The native fallback survives only for a caller with no SessionPort at all.
 */
class Session
{
    public function __construct(
        private readonly ?SessionPort $store = null,
    ) {
        if ($this->store === null && \PHP_SAPI !== 'cli' && session_status() === \PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function put(string $key, mixed $value): void
    {
        if ($this->store !== null) {
            $this->store->put(self::key($key), $value);
            return;
        }

        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->store !== null) {
            return $this->store->get(self::key($key), $default);
        }

        return $_SESSION[$key] ?? $default;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        if ($this->store !== null) {
            return $this->store->pull(self::key($key), $default);
        }

        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public function has(string $key): bool
    {
        if ($this->store !== null) {
            return $this->store->has(self::key($key));
        }

        return isset($_SESSION[$key]);
    }

    /** Namespaced so `state` cannot collide with anything else the app keeps in its session. */
    private static function key(string $key): string
    {
        return 'social_auth.' . $key;
    }
}
