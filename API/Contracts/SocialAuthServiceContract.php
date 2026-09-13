<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\SocialAuth\Socialite\Ports\User as SocialUser;

/**
 * Published social-authentication contract.
 *
 * Other modules (e.g. an Auth module) depend on this to start an OAuth
 * redirect and to resolve the returning user. The Socialite engine stays
 * internal to this plugin.
 */
interface SocialAuthServiceContract
{
    /**
     * Build the provider authorization redirect URL for $driver
     * (e.g. 'github', 'google', 'facebook').
     *
     * $siteBase is the scheme://host of the site the browser is on. A relative
     * `{DRIVER}_REDIRECT_URI` is resolved against it (unless SOCIAL_AUTH_BASE_URL
     * pins one host), so a project serving several hosts sends each user back to
     * the host that holds their session. The callback leg derives the same base
     * from its request, and the provider requires the two to match.
     */
    public function redirectUrl(string $driver, string $siteBase = ''): string;

    /**
     * Resolve the authenticated social user from the OAuth callback request.
     */
    public function userFromCallback(string $driver, Request $request): SocialUser;
}
