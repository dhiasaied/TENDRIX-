<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates in-app notifications for users or for role-filtered members of an organization.
 */
final class Notifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param list<string> $roles only members holding at least one of these roles (empty = all members)
     */
    public function notifyOrganization(?Organization $org, array $roles, string $type, string $title, string $message, ?string $link = null, ?int $excludeUserId = null): int
    {
        if (null === $org) {
            return 0;
        }
        $count = 0;
        foreach ($this->users->findActiveMembers($org, $roles) as $user) {
            if ($user->getId() !== $excludeUserId) {
                $this->notifyUser($user, $type, $title, $message, $link);
                ++$count;
            }
        }

        return $count;
    }

    public function notifyUser(?User $user, string $type, string $title, string $message, ?string $link = null): void
    {
        if (null === $user || !$user->isActive()) {
            return;
        }
        $this->em->persist(new Notification($user, $type, $title, $message, $link));
    }
}
