<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\Contracts\RefreshTokenServiceContract;
use Plugins\Auth\Infrastructure\Http\Controllers\SessionStartController;
use Plugins\Session\Infrastructure\Http\StartSessionStage;
use Plugins\SocialAuth\API\Contracts\SocialAuthServiceContract;
use Plugins\SocialAuth\Application\Services\SocialLoginService;
use Plugins\SocialAuth\Infrastructure\Gateways\ProviderTokenGateway;
use Plugins\SocialAuth\Socialite\Ports\User as SocialUser;
use Plugins\User\API\DTOs\UserDTO;
use Project\Http\Controllers\ApiController;
use Project\Http\Controllers\Concerns\InteractsWithAuthManager;

/**
 * SocialAuthController — social sign-in, end to end.
 *
 *   GET  /auth/social/{driver}           → 302 to the provider's consent page
 *   GET  /auth/social/{driver}/callback  → provider round-trip:
 *          default      → platform session login + redirect (web flow);
 *                         a failure redirects to SOCIAL_AUTH_FAILURE_REDIRECT
 *                         with ?social_error=<slug>
 *          ?mode=token  → 200 { user, tokens } (SPA/native webview flow)
 *   POST /auth/social/{driver}/token     → native-SDK token sign-in (mobile):
 *          { access_token | id_token | identity_token[, name] }
 *          → 200 { user, tokens }
 *
 * The provider profile is resolved to a platform user by SocialLoginService
 * (linked identity → email match → create); the web session is opened by the
 * Auth plugin's `web` guard and tokens come from its published contracts, so
 * the resulting credentials are indistinguishable from a password login.
 */
final class SocialAuthController extends ApiController
{
    use InteractsWithAuthManager;

    /**
     * Internal failure codes → the stable slug a browser failure redirect
     * carries. Anything unlisted is `failed`: the page learns what the person
     * can act on, never an internal code.
     */
    private const FAILURE_SLUGS = [
        'social_auth.cancelled'             => 'cancelled',
        'social_auth.email.unverified'      => 'unverified_email',
        'social_auth.profile.missing_email' => 'missing_email',
        'social_auth.login.no_membership'   => 'no_membership',
    ];

    public function __construct(
        private readonly SocialAuthServiceContract $social,
        private readonly SocialLoginService $login,
        private readonly ProviderTokenGateway $tokens,
        private readonly AuthServiceContract $auth,
        private readonly RefreshTokenServiceContract $refreshTokens,
        private readonly SessionPort $session,
        private readonly int $accessTtl = 3600,
        private readonly string $successRedirect = '/',
        private readonly string $failureRedirect = '/login',
    ) {
    }

    public function redirect(string $driver): Response
    {
        try {
            return Response::redirect($this->social->redirectUrl($driver, $this->resolveRequest()->site()->base()));
        } catch (ServiceException) {
            return $this->notFound("Unknown or unconfigured social provider [{$driver}].");
        }
    }

    public function callback(string $driver): Response
    {
        $request   = $this->resolveRequest();
        $wantsJson = $request->input('mode') === 'token' || $request->expectsJson();

        try {
            // A refusal at the provider (the person pressed Cancel) comes back as
            // ?error=… with no code to exchange. Naming it spares them a generic
            // "sign-in failed" for something they chose.
            if (($request->queryAll()['error'] ?? null) !== null) {
                throw new ServiceException('social_auth.cancelled', layer: 'controller.social_auth', context: ['driver' => $driver]);
            }

            $profile = $this->social->userFromCallback($driver, $request);
            $user    = $this->login->resolveUser($driver, $this->profileArray($profile), $this->tenantId());
        } catch (ServiceException $e) {
            return $this->fail($e, $driver, $wantsJson);
        }

        if ($wantsJson) {
            return $this->ok(['user' => $user->toArray(), 'tokens' => $this->issueTokenPair($user)]);
        }

        // Web flow: the SAME guard a password login goes through. loginUsingId()
        // loads the account membership-aware for this request's tenant, so the
        // session carries that tenant with its roles and permissions and gets its
        // device-session row. A bare AuthServiceContract::startSession() carries
        // none of those — a signed-in person with no role on a tenant host.
        // false = the account has no active membership on this tenant.
        try {
            if ($this->auth('web')->loginUsingId($user->id) === false) {
                throw new ServiceException('social_auth.login.no_membership', layer: 'controller.social_auth', context: ['driver' => $driver]);
            }
        } catch (ServiceException $e) {
            return $this->fail($e, $driver, false);
        }

        // Back to the page recorded by the Session plugin's StartSessionStage
        // when there is one (relative path only — open-redirect guard), else the
        // configured default.
        $previous = $this->session->pull(StartSessionStage::PREVIOUS_URL);
        $target   = self::isRelativePath($previous) ? $previous : $this->successRedirect;

        return Response::redirect($this->throughSessionStart($target));
    }

    /**
     * Route the browser through the Auth plugin's session initializer
     * (GET /auth/session/start, Auth >= 1.9.0), exactly as a password login
     * does: the first page view of the new session is where other plugins —
     * Tenancy stamping the signed-in user into its cookie — set up their
     * per-user state. The marker is what makes the initializer announce the
     * sign-in rather than just redirect on.
     *
     * Against an older Auth, or with AUTH_SESSION_START off, the target is
     * returned unchanged — the redirect this controller always made.
     */
    private function throughSessionStart(string $target): string
    {
        if (!class_exists(SessionStartController::class)
            || \in_array(strtolower(trim((string) (env('AUTH_SESSION_START') ?? '1'))), ['0', 'false', 'off', 'no'], true)) {
            return $target;
        }

        $this->session->put(SessionStartController::PENDING, true);

        return SessionStartController::through($target);
    }

    public function token(string $driver): Response
    {
        $request = $this->resolveRequest();

        try {
            $profile = $this->tokens->verify($driver, [
                'access_token'   => (string) $request->input('access_token', ''),
                'id_token'       => (string) $request->input('id_token', ''),
                'identity_token' => (string) $request->input('identity_token', ''),
                'name'           => (string) $request->input('name', ''),
            ]);
            $user = $this->login->resolveUser($driver, $profile, $this->tenantId());
        } catch (GatewayException $e) {
            return Response::unauthorized($e->getMessage());
        } catch (ServiceException $e) {
            return $this->socialFailure($e);
        }

        return $this->ok(['user' => $user->toArray(), 'tokens' => $this->issueTokenPair($user)]);
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /** The tenant Tenancy's TenantContextStage resolved for this request, '' when none. */
    private function tenantId(): string
    {
        return (string) ($this->resolveRequest()->attribute('tenant') ?? '');
    }

    /** @return array<string,mixed> the old api.md `tokens` shape */
    private function issueTokenPair(UserDTO $user): array
    {
        $request = $this->resolveRequest();
        $refresh = $this->refreshTokens->issue(
            $user->id,
            device: $request->header('User-Agent'),
            ip:     $request->ip(),
        );

        return [
            // Display claims passed explicitly — the user record is already in
            // hand, so AuthService skips its central-lookup enrichment.
            'accessToken'      => $this->auth->issueJwt($user->id, [
                'preferred_username' => $user->username,
                'email'              => $user->email,
            ], $this->accessTtl),
            'tokenType'        => 'Bearer',
            'expiresAt'        => time() + $this->accessTtl,
            'refreshToken'     => $refresh->token,
            'refreshExpiresAt' => $refresh->expiresAt,
        ];
    }

    /** @return array{id:string,email:?string,email_verified:bool,name:?string,nickname:?string,avatar:?string} */
    private function profileArray(SocialUser $user): array
    {
        return [
            'id'             => (string) $user->getId(),
            'email'          => $user->getEmail(),
            // This flag used to be dropped here, so the service linked by email
            // alone — an attacker who set a victim's address as their UNVERIFIED
            // provider email was linked straight onto the victim's account.
            'email_verified' => self::providerVerifiedEmail($user->getRaw()),
            'name'           => $user->getName(),
            'nickname'       => $user->getNickname(),
            'avatar'         => $user->getAvatar(),
        ];
    }

    /**
     * Whether the PROVIDER asserts the email is verified.
     *
     * Defaults to FALSE for any provider that does not say so. A provider we do
     * not understand must never be trusted to have verified anything — silence
     * is not an assertion.
     *
     * @param array<string, mixed> $raw the provider's own payload
     */
    private static function providerVerifiedEmail(array $raw): bool
    {
        foreach (['email_verified', 'verified_email', 'verified'] as $key) {
            if (!\array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];

            // Google sends a real bool; Apple sends the string "true".
            return $value === true
                || $value === 1
                || (\is_string($value) && \strtolower($value) === 'true');
        }

        return false;
    }

    /**
     * A failed callback. JSON callers keep the 422 envelope; a BROWSER is sent
     * back to the failure page, because a top-level navigation that ends on a
     * raw JSON body is a dead end for the person reading it.
     */
    private function fail(ServiceException $e, string $driver, bool $wantsJson): Response
    {
        $this->report($e, $driver);

        if ($wantsJson) {
            return $this->socialFailure($e);
        }

        $target = self::isRelativePath($this->failureRedirect) ? $this->failureRedirect : '/login';
        $slug   = self::FAILURE_SLUGS[$e->getMessage()] ?? 'failed';

        return Response::redirect($target . (str_contains($target, '?') ? '&' : '?') . 'social_error=' . $slug);
    }

    /**
     * Leave a trace of why a sign-in failed — the redirect shows the person a
     * slug, which on its own tells an operator nothing about a misconfigured
     * client or a rejected redirect URI. The cause's message is the provider's
     * own error; the code, state and tokens never appear in it (they travel in
     * the request body and headers, not the URL). A cancel is not a fault.
     */
    private function report(ServiceException $e, string $driver): void
    {
        if ($e->getMessage() === 'social_auth.cancelled') {
            return;
        }

        $container = $this->resolveRequest()->container();
        if ($container === null || !$container->has(LoggerPort::class)) {
            return;
        }

        $cause = $e->getPrevious();
        $container->make(LoggerPort::class)->warning('Social sign-in failed', [
            'code'   => $e->getMessage(),
            'driver' => $driver,
            'cause'  => $cause !== null ? $cause::class . ': ' . mb_substr($cause->getMessage(), 0, 300) : null,
        ]);
    }

    private function socialFailure(ServiceException $e): Response
    {
        $message = match ($e->getMessage()) {
            'social_auth.profile.missing_email' =>
                'This provider account has no verified email — sign in with a provider that shares one, or register first.',
            'social_auth.email.unverified' =>
                'Your provider has not verified this email address. Verify it with them and try again, '
                . 'or sign in with your password and link the account from your profile.',
            'social_auth.cancelled' => 'Sign-in was cancelled at the provider.',
            default => 'Social sign-in failed. Please try again.',
        };

        return Response::json(['error' => ['code' => $e->getMessage(), 'message' => $message]], 422);
    }

    /** A same-site path: rejects absolute, protocol-relative ('//') and backslash ('/\') targets. */
    private static function isRelativePath(mixed $candidate): bool
    {
        return \is_string($candidate)
            && $candidate !== ''
            && $candidate[0] === '/'
            && !str_starts_with($candidate, '//')
            && !str_starts_with($candidate, '/\\');
    }
}
