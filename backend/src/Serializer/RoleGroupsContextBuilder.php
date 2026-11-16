<?php

namespace App\Serializer;

use ApiPlatform\State\SerializerContextBuilderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Field-level access control through serialization groups:
 *  - reading: "<resource>:internal" groups (AI pricing and scoring, internal comments, competition
 *    data, staff identities) are only serialized for the buyer side and administrators;
 *  - writing: administrators may set privileged fields (roles, organization, active flags) through
 *    the "<resource>:admin" group added next to every "<resource>:write" group.
 */
#[AsDecorator('api_platform.serializer.context_builder')]
final class RoleGroupsContextBuilder implements SerializerContextBuilderInterface
{
    private const INTERNAL_READ_GROUPS = ['tender:internal', 'proposal:internal'];

    public function __construct(
        #[AutowireDecorated] private readonly SerializerContextBuilderInterface $decorated,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->decorated->createFromRequest($request, $normalization, $extractedAttributes);
        if (!isset($context['groups'])) {
            return $context;
        }
        $groups = (array) $context['groups'];

        if ($normalization) {
            if ($this->isBuyerSideOrAdmin()) {
                array_push($groups, ...self::INTERNAL_READ_GROUPS);
            }
        } elseif ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            foreach ($groups as $group) {
                if (str_ends_with($group, ':write')) {
                    $groups[] = substr($group, 0, -\strlen(':write')).':admin';
                }
            }
        }

        $context['groups'] = array_values(array_unique($groups));

        return $context;
    }

    private function isBuyerSideOrAdmin(): bool
    {
        foreach (['ROLE_ADMIN', 'ROLE_BUYER', 'ROLE_REQUESTER'] as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
