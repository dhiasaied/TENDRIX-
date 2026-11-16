<?php

namespace App\Security\Voter;

use App\Entity\Organization;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ORG_MEMBER: the current user belongs to the given organization (compared by id, never by object graph).
 *
 * @extends Voter<string, Organization|null>
 */
final class OrganizationMemberVoter extends Voter
{
    public const MEMBER = 'ORG_MEMBER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MEMBER === $attribute && ($subject instanceof Organization || null === $subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User
            && $subject instanceof Organization
            && null !== $subject->getId()
            && $user->getOrganization()?->getId() === $subject->getId();
    }
}
