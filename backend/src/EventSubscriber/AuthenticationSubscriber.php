<?php

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Session lifecycle around the JWT:
 *  - tokens carry a session version ("sv"); a token whose version is older than the user's is
 *    rejected, which makes logout, password changes and deactivations revoke sessions at once;
 *  - the login response returns a non-sensitive profile for the SPA (the token itself only
 *    travels in the httpOnly cookie);
 *  - successful logins are recorded in the audit trail.
 */
final class AuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_CREATED => 'onJwtCreated',
            Events::JWT_DECODED => 'onJwtDecoded',
            Events::AUTHENTICATION_SUCCESS => 'onAuthenticationSuccess',
        ];
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        // Keep the token minimal: identity, roles and session version only (no names or organization data).
        $payload = $event->getData();
        $payload['sv'] = $user->getSessionVersion();
        $event->setData($payload);
    }

    public function onJwtDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $user = isset($payload['email']) ? $this->users->findOneBy(['email' => $payload['email']]) : null;

        if (!$user instanceof User || ($payload['sv'] ?? null) !== $user->getSessionVersion()) {
            $event->markAsInvalid();
        }
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->persist(new AuditLog($user->getEmail(), 'auth.login', 'user', (int) $user->getId()));
        $this->em->flush();

        $data = $event->getData();
        $data['user'] = self::sessionView($user);
        $event->setData($data);
    }

    /**
     * @return array{id: int|null, email: string, fullName: string, roles: list<string>, organization: array{id: int|null, name: string|null, type: string|null}}
     */
    public static function sessionView(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'fullName' => $user->getFullName(),
            'roles' => $user->getRoles(),
            'organization' => [
                'id' => $user->getOrganization()?->getId(),
                'name' => $user->getOrganization()?->getName(),
                'type' => $user->getOrganization()?->getType(),
            ],
        ];
    }
}
