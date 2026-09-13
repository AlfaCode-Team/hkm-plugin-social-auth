<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request as KernelRequest;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\SocialAuth\API\Contracts\SocialAuthServiceContract;
use Plugins\SocialAuth\Socialite\Http\Request as SocialiteRequest;
use Plugins\SocialAuth\Socialite\Http\Session as SocialiteSession;
use Plugins\SocialAuth\Socialite\Ports\User as SocialUser;
use Plugins\SocialAuth\Socialite\SocialiteManager;

/**
 * GDA service wrapping the ported Socialite engine.
 *
 * Builds a per-request SocialiteManager seeded with provider credentials and a
 * request factory so the stateful OAuth flow (CSRF state / PKCE) keeps working
 * inside the otherwise-stateless kernel. Both legs keep that state in the
 * application's SessionPort when one is bound.
 */
final class SocialAuthService implements SocialAuthServiceContract
{
    /**
     * @param array<string,mixed> $config  provider credentials keyed under "services"
     * @param string              $baseUrl SOCIAL_AUTH_BASE_URL — when set, pins every
     *                                     relative redirect URI to this one host
     */
    public function __construct(
        private readonly array $config,
        private readonly string $baseUrl,
        private readonly ?SessionPort $session = null,
    ) {
    }

    public function redirectUrl(string $driver, string $siteBase = ''): string
    {
        try {
            $manager = $this->manager(
                fn (): SocialiteRequest => new SocialiteRequest([], new SocialiteSession($this->session)),
                $siteBase,
            );

            return $manager->driver($driver)->redirect()->getTargetUrl();
        } catch (\Throwable $e) {
            throw new ServiceException(
                'social_auth.redirect.failed',
                layer: 'service.social_auth',
                context: ['driver' => $driver],
                previous: $e,
            );
        }
    }

    public function userFromCallback(string $driver, KernelRequest $request): SocialUser
    {
        try {
            $manager = $this->manager(
                fn (): SocialiteRequest => SocialiteRequest::fromKernel($request, $this->session),
                $request->site()->base(),
            );

            return $manager->driver($driver)->user();
        } catch (\Throwable $e) {
            throw new ServiceException(
                'social_auth.callback.failed',
                layer: 'service.social_auth',
                context: ['driver' => $driver],
                previous: $e,
            );
        }
    }

    private function manager(callable $requestFactory, string $siteBase): SocialiteManager
    {
        // The configured base wins: a deployment behind a proxy that rewrites the
        // Host needs one fixed public origin. Unset, each request's own host is
        // used, which is what a multi-host project needs.
        return new SocialiteManager($this->config, $requestFactory, $this->baseUrl !== '' ? $this->baseUrl : $siteBase);
    }
}
