<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\SocialAuth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request as KernelRequest;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\SocialAuth\Application\Services\SocialAuthService;
use Plugins\SocialAuth\Application\Services\SocialLoginService;
use Plugins\SocialAuth\Infrastructure\Persistence\SocialIdentityRepository;
use Plugins\SocialAuth\Socialite\Two\InvalidStateException;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UserDTO;
use Plugins\User\API\DTOs\VerifyEmailResult;

/**
 * The browser flow on a multi-host, multi-tenant deployment: where the OAuth
 * state lives, which host the provider sends the browser back to, and which
 * account and tenant a sign-in resolves to. None of it touches the network —
 * every assertion is decided before an HTTP call would be made.
 */
#[CoversClass(SocialAuthService::class)]
#[CoversClass(SocialLoginService::class)]
final class SocialAuthFlowTest extends TestCase
{
    // ── state + redirect URI ────────────────────────────────────────────────

    public function test_the_state_is_kept_in_the_application_session(): void
    {
        $session = self::session();
        $query   = self::query((new SocialAuthService(self::config(), '', $session))
            ->redirectUrl('google', 'https://kes.hkmstd.test'));

        self::assertSame(40, \strlen($query['state']));
        self::assertSame($query['state'], $session->get('social_auth.state'));
    }

    public function test_a_relative_redirect_uri_returns_to_the_host_the_browser_is_on(): void
    {
        $service = new SocialAuthService(self::config(), '', self::session());

        self::assertSame(
            'https://kes.hkmstd.test/auth/social/google/callback',
            self::query($service->redirectUrl('google', 'https://kes.hkmstd.test'))['redirect_uri'],
        );
        self::assertSame(
            'https://ug.hkmstd.test/auth/social/google/callback',
            self::query($service->redirectUrl('google', 'https://ug.hkmstd.test'))['redirect_uri'],
        );
    }

    public function test_a_configured_base_url_pins_the_redirect_host(): void
    {
        $service = new SocialAuthService(self::config(), 'https://id.example.test', self::session());

        self::assertSame(
            'https://id.example.test/auth/social/google/callback',
            self::query($service->redirectUrl('google', 'https://kes.hkmstd.test'))['redirect_uri'],
        );
    }

    public function test_a_callback_whose_state_does_not_match_is_refused_and_the_state_is_spent(): void
    {
        $session = self::session();
        $session->put('social_auth.state', 'issued-state');

        self::assertCallbackRefused($session, ['state' => 'forged-state', 'code' => 'x']);
        self::assertFalse($session->has('social_auth.state'), 'a state is single-use, match or not');
    }

    public function test_a_callback_with_no_issued_state_is_refused(): void
    {
        // An empty returned state must not "match" an absent one.
        self::assertCallbackRefused(self::session(), ['state' => '', 'code' => 'x']);
    }

    // ── account resolution ──────────────────────────────────────────────────

    public function test_a_returning_user_is_loaded_without_the_guest_permission_gate(): void
    {
        $users = $this->createMock(UserServiceContract::class);
        $users->expects(self::once())->method('find')->with('user-1', false, true)->willReturn(self::user());
        $users->expects(self::never())->method('registerPublic');

        $user = $this->loginService(['user_id' => 'user-1'], $users)
            ->resolveUser('google', self::profile(), 'kes');

        self::assertSame('user-1', $user->id);
    }

    public function test_a_first_sign_in_registers_the_account_on_the_requesting_tenant(): void
    {
        $registered = null;

        $users = $this->createStub(UserServiceContract::class);
        $users->method('findByIdentifier')->willReturn(null, self::user());
        $users->method('verifyEmailByToken')->willReturn(VerifyEmailResult::ok());
        $users->method('registerPublic')->willReturnCallback(static function (RegisterUserDTO $dto) use (&$registered): string {
            $registered = $dto;
            return 'verification-token';
        });

        $this->loginService(null, $users)->resolveUser('google', self::profile(), 'kes');

        self::assertInstanceOf(RegisterUserDTO::class, $registered);
        self::assertSame('kes', $registered->tenantId);
        self::assertSame('jane@example.test', $registered->email->value());
        self::assertSame(['first_name' => 'Jane', 'last_name' => 'Doe'], $registered->profile);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param array<string,string> $query */
    private static function assertCallbackRefused(SessionPort $session, array $query): void
    {
        $request = KernelRequest::build('GET', '/auth/social/google/callback', query: $query);

        try {
            (new SocialAuthService(self::config(), '', $session))->userFromCallback('google', $request);
            self::fail('a callback without a matching state must be refused');
        } catch (ServiceException $e) {
            self::assertSame('social_auth.callback.failed', $e->getMessage());
            self::assertInstanceOf(InvalidStateException::class, $e->getPrevious());
        }
    }

    /** @param array<string,string>|null $linked the social_identities row, null for none */
    private function loginService(?array $linked, UserServiceContract $users): SocialLoginService
    {
        $db = $this->createStub(DatabasePort::class);
        $db->method('queryOne')->willReturn($linked);

        return new SocialLoginService(new SocialIdentityRepository($db), $users);
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        return ['services' => ['google' => [
            'client_id'     => 'client-id',
            'client_secret' => 'client-secret',
            'redirect'      => '/auth/social/google/callback',
        ]]];
    }

    /** @return array<string,string> */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array{id:string,email:string,email_verified:bool,name:string,nickname:null,avatar:null} */
    private static function profile(): array
    {
        return [
            'id'             => 'google-sub-1',
            'email'          => 'Jane@Example.test',
            'email_verified' => true,
            'name'           => 'Jane Doe',
            'nickname'       => null,
            'avatar'         => null,
        ];
    }

    private static function user(): UserDTO
    {
        return new UserDTO(
            id: 'user-1',
            username: 'jane_ab12',
            email: 'jane@example.test',
            emailVerified: true,
            createdAt: '2026-09-13T00:00:00+00:00',
        );
    }

    /** An in-memory SessionPort — only the key/value surface the OAuth flow uses is meaningful. */
    private static function session(): SessionPort
    {
        return new class implements SessionPort {
            /** @var array<string,mixed> */
            private array $data = [];

            public function start(?string $id = null): void {}
            public function id(): string { return 'test'; }
            public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            public function put(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function has(string $key): bool { return isset($this->data[$key]); }
            public function pull(string $key, mixed $default = null): mixed
            {
                $value = $this->data[$key] ?? $default;
                unset($this->data[$key]);
                return $value;
            }
            public function push(string $key, mixed $value): void { $this->data[$key][] = $value; }
            public function increment(string $key, int $by = 1): int { return $this->data[$key] = (int) ($this->data[$key] ?? 0) + $by; }
            public function forget(string $key): void { unset($this->data[$key]); }
            public function flush(): void { $this->data = []; }
            public function all(): array { return $this->data; }
            public function flash(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function reflash(): void {}
            public function token(): string { return 'token'; }
            public function regenerateToken(): void {}
            public function regenerate(): void {}
            public function invalidate(): void { $this->data = []; }
            public function shouldPersist(): bool { return $this->data !== []; }
            public function save(): void {}
        };
    }
}
