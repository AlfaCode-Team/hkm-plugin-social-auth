<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\SocialAuth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\SocialAuth\Application\Services\SocialLoginService;
use Plugins\SocialAuth\Socialite\Two\AbstractProvider;

/**
 * Regression cover for SA-01 and SA-02.
 */
#[CoversClass(SocialLoginService::class)]
#[CoversClass(AbstractProvider::class)]
final class SocialLoginSecurityTest extends TestCase
{
    /**
     * Stubs, not fakes: the unverified-email check fires before either
     * collaborator is used for anything, so behaviour beyond "no linked
     * identity" is irrelevant here.
     */
    private function service(): SocialLoginService
    {
        // SocialIdentityRepository is final, so it cannot be doubled — build a
        // real one over a stubbed DatabasePort that finds no existing link.
        $db = $this->createStub(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class);
        $db->method('queryOne')->willReturn(null);

        return new SocialLoginService(
            new \Plugins\SocialAuth\Infrastructure\Persistence\SocialIdentityRepository($db),
            $this->createStub(\Plugins\User\API\Contracts\UserServiceContract::class),
        );
    }

    // ── SA-01: the state token must be unguessable ──────────────────────────

    public function test_random_token_is_the_requested_length_and_not_repeating(): void
    {
        $m = new \ReflectionMethod(AbstractProvider::class, 'randomToken');

        $a = $m->invoke(null, 40);
        $b = $m->invoke(null, 40);

        self::assertSame(40, \strlen($a));
        self::assertSame(96, \strlen($m->invoke(null, 96)));
        self::assertNotSame($a, $b, 'two state tokens must never collide');
    }

    public function test_the_state_check_has_no_global_kill_switch(): void
    {
        // SA-01: hasInvalidState() opened with
        //     if (SUPPORTED_SOCIAL_STATELESS_ALLOW) return false;
        // on a constant defined nowhere. Defining it truthy would have disabled
        // the OAuth CSRF check for every provider.
        // Strip comments before scanning: this guards against the CODE coming
        // back, and the comments deliberately name both dead symbols to explain
        // why they must not.
        $code = '';
        foreach (\token_get_all((string) file_get_contents(
            \dirname(__DIR__) . '/Socialite/Two/AbstractProvider.php'
        )) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }

        self::assertStringNotContainsString('SUPPORTED_SOCIAL_STATELESS_ALLOW', $code);
        self::assertStringNotContainsString('str_random(', $code, 'str_random() is undefined here');
    }

    // ── SA-02: no linking on an unverified provider email ───────────────────

    public function test_unverified_provider_email_is_refused(): void
    {
        $service = $this->service();

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('social_auth.email.unverified');

        // An attacker sets a victim's address as their own provider email and
        // never verifies it. This used to link them onto the victim's account.
        $service->resolveUser('google', [
            'id'             => 'attacker-provider-id',
            'email'          => 'victim@example.test',
            'email_verified' => false,
            'name'           => 'Attacker',
            'nickname'       => null,
            'avatar'         => null,
        ]);
    }

    public function test_a_missing_verified_flag_is_treated_as_unverified(): void
    {
        $service = $this->service();

        // Silence is not an assertion — a provider we do not understand must
        // never be trusted to have verified anything.
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('social_auth.email.unverified');

        $service->resolveUser('unknown-provider', [
            'id'       => 'x',
            'email'    => 'victim@example.test',
            'name'     => null,
            'nickname' => null,
            'avatar'   => null,
        ]);
    }
}
