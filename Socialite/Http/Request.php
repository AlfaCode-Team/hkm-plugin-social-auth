<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Socialite\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request as KernelRequest;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

/**
 * Stateful request wrapper the OAuth providers expect.
 *
 * Adapts the kernel's immutable, stateless Request to the small surface the
 * ported Socialite providers use (input() + session()). Build one per
 * incoming HTTP request from the kernel Request.
 */
class Request
{
    /** @param array<string,mixed> $input */
    public function __construct(
        private array $input = [],
        private ?Session $session = null,
    ) {
        $this->session ??= new Session();
    }

    /**
     * Pass the request's SessionPort: without it the OAuth state falls back to
     * native PHP sessions (see Session for why that fails under OpenSwoole).
     */
    public static function fromKernel(KernelRequest $request, ?SessionPort $session = null): self
    {
        return new self(array_merge($request->queryAll(), $request->all()), new Session($session));
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function session(): Session
    {
        return $this->session;
    }
}
