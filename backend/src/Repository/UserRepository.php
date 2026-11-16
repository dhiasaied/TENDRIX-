<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Rehash passwords transparently when the hashing algorithm cost changes. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * @param list<string> $roles members holding at least one of these roles (empty = all)
     *
     * @return list<User>
     */
    public function findActiveMembers(Organization $org, array $roles = []): array
    {
        /** @var list<User> $members */
        $members = $this->findBy(['organization' => $org, 'active' => true]);

        if ([] === $roles) {
            return $members;
        }

        // Roles are a JSON column: filter in PHP to stay portable across PostgreSQL/MySQL/SQLite.
        return array_values(array_filter($members, static fn (User $u) => [] !== array_intersect($roles, $u->getRoles())));
    }
}
