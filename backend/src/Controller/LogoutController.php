<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Security\SessionCookieRefresher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Server-side logout: revokes every token of the user (all devices) and clears the cookie.
 */
final class LogoutController
{
    #[Route('/api/auth/logout', name: 'api_logout', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] User $user, EntityManagerInterface $em, SessionCookieRefresher $cookies): Response
    {
        $user->revokeSessions();
        $em->persist(new AuditLog($user->getEmail(), 'auth.logout', 'user', (int) $user->getId()));
        $em->flush();

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->setCookie($cookies->clearingCookie());

        return $response;
    }
}
