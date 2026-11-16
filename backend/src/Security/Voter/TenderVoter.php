<?php

namespace App\Security\Voter;

use App\Entity\Tender;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * TENDER_EDIT: content may be modified while the tender is a draft (or awaiting approval, for buyers).
 * Requesters may only edit the tenders they authored; buyers may edit any tender of their organization.
 *
 * @extends Voter<string, Tender>
 */
final class TenderVoter extends Voter
{
    public const EDIT = 'TENDER_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Tender;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || $user->getOrganization()?->getId() !== $subject->getOrganization()?->getId()) {
            return false;
        }

        if ($user->hasRole(User::ROLE_BUYER)) {
            return \in_array($subject->getStatus(), [Tender::STATUS_DRAFT, Tender::STATUS_PENDING_APPROVAL], true);
        }

        return $user->hasRole(User::ROLE_REQUESTER)
            && Tender::STATUS_DRAFT === $subject->getStatus()
            && $subject->getCreatedBy()?->getId() === $user->getId();
    }
}
