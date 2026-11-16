<?php

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Cookie\JWTCookieProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Issues a fresh session cookie in the current response, after an action that revoked the
 * caller's own sessions (password change), so the user stays signed in on this device only.
 */
final class SessionCookieRefresher
{
    public const COOKIE = 'BEARER';

    private ?User $pending = null;

    public function __construct(
        private readonly JWTTokenManagerInterface $tokens,
        #[Autowire(service: 'lexik_jwt_authentication.cookie_provider.BEARER')]
        private readonly JWTCookieProvider $cookieProvider,
        #[Autowire('%env(bool:JWT_COOKIE_SECURE)%')]
        private readonly bool $secureCookie,
    ) {
    }

    public function reissueFor(User $user): void
    {
        $this->pending = $user;
    }

    /** Expired cookie with the same scope and flags as the session cookie, used on logout. */
    public function clearingCookie(): Cookie
    {
        return Cookie::create(self::COOKIE, '', 1, '/api', null, $this->secureCookie, true, false, Cookie::SAMESITE_STRICT);
    }

    #[AsEventListener(event: 'kernel.response', priority: -10)]
    public function onResponse(ResponseEvent $event): void
    {
        if (null === $this->pending || !$event->isMainRequest() || $event->getResponse()->getStatusCode() >= 400) {
            return;
        }
        $event->getResponse()->headers->setCookie($this->cookieProvider->createCookie($this->tokens->create($this->pending)));
        $this->pending = null;
    }
}
