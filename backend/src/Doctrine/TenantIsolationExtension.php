<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Proposal;
use App\Entity\SupplierEvaluation;
use App\Entity\Tender;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Multi-tenant row-level security: every collection and item query issued by API Platform is
 * restricted to the rows the current user's organization is entitled to see. Rows outside that
 * scope simply do not exist for the caller (404), which avoids leaking their existence.
 */
final class TenantIsolationExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(QueryBuilder $qb, QueryNameGeneratorInterface $qng, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($qb, $qng, $resourceClass);
    }

    public function applyToItem(QueryBuilder $qb, QueryNameGeneratorInterface $qng, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($qb, $qng, $resourceClass);
    }

    private function restrict(QueryBuilder $qb, QueryNameGeneratorInterface $qng, string $resourceClass): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // Anonymous access is rejected by operation security beforehand.
        }

        $root = $qb->getRootAliases()[0];

        // Notifications are personal, even for administrators.
        if (Notification::class === $resourceClass) {
            $qb->andWhere("$root.recipient = :tenant_user")->setParameter('tenant_user', $user->getId());

            return;
        }

        if ($this->security->isGranted(User::ROLE_ADMIN)) {
            return;
        }

        $org = $user->getOrganization();
        $orgId = $org?->getId() ?? 0;
        $isSupplier = (bool) $org?->isSupplier();

        switch ($resourceClass) {
            case Organization::class:
                $qb->andWhere("$root.id = :tenant_org OR $root.type = :tenant_visible_type")
                    ->setParameter('tenant_org', $orgId)
                    ->setParameter('tenant_visible_type', $isSupplier ? Organization::TYPE_BUYER : Organization::TYPE_SUPPLIER);
                break;

            case User::class:
                $qb->andWhere("$root.organization = :tenant_org")->setParameter('tenant_org', $orgId);
                break;

            case Tender::class:
                if ($isSupplier) {
                    $invited = $qng->generateJoinAlias('invited');
                    $proposal = $qng->generateJoinAlias('own_proposal');
                    $qb->andWhere(\sprintf(
                        '(%1$s.status = :tenant_published AND (SIZE(%1$s.invitedSuppliers) = 0 OR EXISTS (SELECT %2$s.id FROM %3$s %4$s JOIN %4$s.invitedSuppliers %2$s WHERE %4$s = %1$s AND %2$s.id = :tenant_org)))'
                        .' OR EXISTS (SELECT %5$s.id FROM %6$s %5$s WHERE %5$s.tender = %1$s AND %5$s.supplier = :tenant_org)',
                        $root, $invited, Tender::class, $qng->generateJoinAlias('t_inv'), $proposal, Proposal::class,
                    ))
                        ->setParameter('tenant_published', Tender::STATUS_PUBLISHED)
                        ->setParameter('tenant_org', $orgId);
                } else {
                    $qb->andWhere("$root.organization = :tenant_org")->setParameter('tenant_org', $orgId);
                }
                break;

            case Proposal::class:
                if ($isSupplier) {
                    $qb->andWhere("$root.supplier = :tenant_org");
                } else {
                    $tender = $qng->generateJoinAlias('tender');
                    $qb->innerJoin("$root.tender", $tender)
                        ->andWhere("$tender.organization = :tenant_org")
                        ->andWhere("$root.status != :tenant_withdrawn")
                        ->setParameter('tenant_withdrawn', Proposal::STATUS_WITHDRAWN);
                }
                $qb->setParameter('tenant_org', $orgId);
                break;

            case Contract::class:
                $qb->andWhere($isSupplier ? "$root.supplier = :tenant_org" : "$root.buyer = :tenant_org")
                    ->setParameter('tenant_org', $orgId);
                break;

            case Invoice::class:
                if ($isSupplier) {
                    $qb->andWhere("$root.supplier = :tenant_org");
                } else {
                    // Buyers never see supplier drafts.
                    $qb->andWhere("$root.buyer = :tenant_org AND $root.status != :tenant_draft")
                        ->setParameter('tenant_draft', Invoice::STATUS_DRAFT);
                }
                $qb->setParameter('tenant_org', $orgId);
                break;

            case SupplierEvaluation::class:
                if ($isSupplier) {
                    $qb->andWhere("$root.supplier = :tenant_org");
                } else {
                    $contract = $qng->generateJoinAlias('contract');
                    $qb->innerJoin("$root.contract", $contract)->andWhere("$contract.buyer = :tenant_org");
                }
                $qb->setParameter('tenant_org', $orgId);
                break;
        }
    }
}
