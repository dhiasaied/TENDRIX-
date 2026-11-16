<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SupplierPerformance;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Organization;
use App\Entity\Proposal;
use App\Entity\SupplierEvaluation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<SupplierPerformance>
 */
final class SupplierPerformanceProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** @return list<SupplierPerformance> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $org = $user->getOrganization();
        $isAdmin = $user->hasRole(User::ROLE_ADMIN);

        $suppliersQb = $this->em->getRepository(Organization::class)->createQueryBuilder('o')
            ->where('o.type = :type')->setParameter('type', Organization::TYPE_SUPPLIER)->orderBy('o.name');
        if (!$isAdmin && $org?->isSupplier()) {
            $suppliersQb->andWhere('o.id = :me')->setParameter('me', $org->getId());
        }
        /** @var list<Organization> $suppliers */
        $suppliers = $suppliersQb->getQuery()->getResult();

        // Buyers only see figures about their own relationship with each supplier.
        $buyerScope = (!$isAdmin && $org?->isBuyer()) ? $org : null;

        $proposals = $this->grouped(
            $this->em->createQueryBuilder()->from(Proposal::class, 'p')->join('p.tender', 't')
                ->select('IDENTITY(p.supplier) AS sid, COUNT(p.id) AS total, SUM(CASE WHEN p.status = :accepted THEN 1 ELSE 0 END) AS accepted, SUM(CASE WHEN p.status IN (:decided) THEN 1 ELSE 0 END) AS decided, AVG(p.matchScore) AS score, AVG(p.dailyRate) AS rate')
                ->setParameter('accepted', Proposal::STATUS_ACCEPTED)
                ->setParameter('decided', [Proposal::STATUS_ACCEPTED, Proposal::STATUS_REJECTED])
                ->groupBy('p.supplier'),
            $buyerScope ? 't.organization = :buyer' : null, $buyerScope,
        );
        $contracts = $this->grouped(
            $this->em->createQueryBuilder()->from(Contract::class, 'c')
                ->select('IDENTITY(c.supplier) AS sid, COUNT(c.id) AS total, SUM(CASE WHEN c.status = :active THEN 1 ELSE 0 END) AS active')
                ->setParameter('active', Contract::STATUS_ACTIVE)
                ->groupBy('c.supplier'),
            $buyerScope ? 'c.buyer = :buyer' : null, $buyerScope,
        );
        $billing = $this->grouped(
            $this->em->createQueryBuilder()->from(Invoice::class, 'i')
                ->select('IDENTITY(i.supplier) AS sid, SUM(i.amountExclTaxCents) AS total')
                ->where('i.status IN (:billed)')->setParameter('billed', [Invoice::STATUS_APPROVED, Invoice::STATUS_PAID])
                ->groupBy('i.supplier'),
            $buyerScope ? 'i.buyer = :buyer' : null, $buyerScope,
        );
        $ratings = $this->grouped(
            $this->em->createQueryBuilder()->from(SupplierEvaluation::class, 'e')
                ->select('IDENTITY(e.supplier) AS sid, COUNT(e.id) AS total, AVG(e.quality) AS quality, AVG(e.reactivity) AS reactivity, AVG(e.communication) AS communication, AVG(e.deadlines) AS deadlines')
                ->groupBy('e.supplier'),
            null, null,
        );

        $result = [];
        foreach ($suppliers as $supplier) {
            $id = (int) $supplier->getId();
            $p = $proposals[$id] ?? null;
            $c = $contracts[$id] ?? null;
            $r = $ratings[$id] ?? null;

            // Buyers only list suppliers they have actually worked with or received proposals from.
            if ($buyerScope && !$p && !$c) {
                continue;
            }

            $row = new SupplierPerformance();
            $row->supplierId = $id;
            $row->name = $supplier->getName();
            $row->city = $supplier->getCity();
            $row->proposalsCount = (int) ($p['total'] ?? 0);
            $row->acceptedCount = (int) ($p['accepted'] ?? 0);
            $row->winRate = ($p['decided'] ?? 0) > 0 ? round($p['accepted'] / $p['decided'] * 100, 1) : 0.0;
            $row->averageMatchScore = isset($p['score']) ? round((float) $p['score'], 1) : null;
            $row->averageDailyRate = isset($p['rate']) ? round((float) $p['rate']) : null;
            $row->contractsCount = (int) ($c['total'] ?? 0);
            $row->activeContracts = (int) ($c['active'] ?? 0);
            $row->totalBilled = round(((int) ($billing[$id]['total'] ?? 0)) / 100, 2);
            $row->averageRating = $supplier->getAverageRating();
            $row->evaluationsCount = (int) ($r['total'] ?? 0);
            $row->ratingDetails = [
                'quality' => isset($r['quality']) ? round((float) $r['quality'], 2) : null,
                'reactivity' => isset($r['reactivity']) ? round((float) $r['reactivity'], 2) : null,
                'communication' => isset($r['communication']) ? round((float) $r['communication'], 2) : null,
                'deadlines' => isset($r['deadlines']) ? round((float) $r['deadlines'], 2) : null,
            ];
            $result[] = $row;
        }

        usort($result, static fn (SupplierPerformance $a, SupplierPerformance $b) => [$b->averageRating ?? 0, $b->totalBilled] <=> [$a->averageRating ?? 0, $a->totalBilled]);

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function grouped(QueryBuilder $qb, ?string $scope, ?Organization $buyer): array
    {
        if ($scope) {
            $qb->andWhere($scope)->setParameter('buyer', $buyer);
        }
        $rows = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $rows[(int) $row['sid']] = $row;
        }

        return $rows;
    }
}
