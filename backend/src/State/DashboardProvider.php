<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Dashboard;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Organization;
use App\Entity\Proposal;
use App\Entity\Tender;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<Dashboard>
 */
final class DashboardProvider implements ProviderInterface
{
    private const MONTHS = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Dashboard
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $org = $user->getOrganization();

        if ($user->hasRole(User::ROLE_ADMIN)) {
            return $this->admin();
        }

        return $org?->isSupplier() ? $this->supplier($org) : $this->buyer($org ?? throw new \LogicException('User without organization.'));
    }

    private function buyer(Organization $org): Dashboard
    {
        $d = new Dashboard();
        $d->space = 'buyer';
        $yearStart = new \DateTimeImmutable('first day of january this year midnight');

        $openTenders = $this->count(Tender::class, 'e', 'e.organization = :org AND e.status = :s', ['org' => $org, 's' => Tender::STATUS_PUBLISHED]);
        $pendingApproval = $this->count(Tender::class, 'e', 'e.organization = :org AND e.status = :s', ['org' => $org, 's' => Tender::STATUS_PENDING_APPROVAL]);
        $toReview = (int) $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(Proposal::class, 'p')->join('p.tender', 't')
            ->where('t.organization = :org AND p.status IN (:s)')->setParameter('org', $org)
            ->setParameter('s', [Proposal::STATUS_SUBMITTED, Proposal::STATUS_SHORTLISTED])->getQuery()->getSingleScalarResult();
        $activeContracts = $this->count(Contract::class, 'e', 'e.buyer = :org AND e.status = :s', ['org' => $org, 's' => Contract::STATUS_ACTIVE]);
        $toSign = $this->count(Contract::class, 'e', 'e.buyer = :org AND e.status = :s', ['org' => $org, 's' => Contract::STATUS_PENDING_BUYER_SIGNATURE]);
        $invoicesToApprove = $this->count(Invoice::class, 'e', 'e.buyer = :org AND e.status = :s', ['org' => $org, 's' => Invoice::STATUS_SUBMITTED]);
        $spendYtd = $this->sum(Invoice::class, 'e.amountExclTaxCents', 'e.buyer = :org AND e.status = :s AND e.paidAt >= :from', ['org' => $org, 's' => Invoice::STATUS_PAID, 'from' => $yearStart]) / 100;
        $committed = (float) $this->em->createQueryBuilder()->select('COALESCE(SUM(c.plannedDays * c.dailyRate), 0)')->from(Contract::class, 'c')
            ->where('c.buyer = :org AND c.status = :s')->setParameter('org', $org)->setParameter('s', Contract::STATUS_ACTIVE)->getQuery()->getSingleScalarResult();
        $avgRate = (float) $this->em->createQueryBuilder()->select('COALESCE(AVG(c.dailyRate), 0)')->from(Contract::class, 'c')
            ->where('c.buyer = :org')->setParameter('org', $org)->getQuery()->getSingleScalarResult();

        $d->kpis = [
            $this->kpi('openTenders', 'Appels d\'offres en cours', $openTenders),
            $this->kpi('proposalsToReview', 'Propositions à étudier', $toReview),
            $this->kpi('activeContracts', 'Contrats actifs', $activeContracts),
            $this->kpi('invoicesToApprove', 'Factures à valider', $invoicesToApprove),
            $this->kpi('spendYtd', 'Dépenses payées (année)', $spendYtd, 'currency'),
            $this->kpi('committedValue', 'Engagements en cours', $committed, 'currency'),
            $this->kpi('averageDailyRate', 'TJM moyen contractualisé', round($avgRate), 'currency'),
            $this->kpi('pendingApproval', 'AO à valider', $pendingApproval),
        ];
        $d->monthly = $this->monthly('e.buyer = :org', ['org' => $org]);
        $d->statusBreakdown = [
            'tenders' => $this->breakdown(Tender::class, 'e.organization = :org', ['org' => $org]),
            'contracts' => $this->breakdown(Contract::class, 'e.buyer = :org', ['org' => $org]),
            'invoices' => $this->breakdown(Invoice::class, 'e.buyer = :org AND e.status != :draft', ['org' => $org, 'draft' => Invoice::STATUS_DRAFT]),
        ];
        $d->topPartners = $this->topPartners('e.buyer = :org', ['org' => $org], 'supplier');
        $d->actionItems = array_values(array_filter([
            $pendingApproval ? ['type' => 'tender', 'label' => "$pendingApproval appel(s) d'offres à valider", 'link' => '/tenders?status=pending_approval'] : null,
            $toReview ? ['type' => 'proposal', 'label' => "$toReview proposition(s) à étudier", 'link' => '/proposals'] : null,
            $toSign ? ['type' => 'contract', 'label' => "$toSign contrat(s) à signer", 'link' => '/contracts?status=pending_buyer_signature'] : null,
            $invoicesToApprove ? ['type' => 'invoice', 'label' => "$invoicesToApprove facture(s) à valider", 'link' => '/invoices?status=submitted'] : null,
        ]));

        return $d;
    }

    private function supplier(Organization $org): Dashboard
    {
        $d = new Dashboard();
        $d->space = 'supplier';
        $yearStart = new \DateTimeImmutable('first day of january this year midnight');

        $opportunities = (int) $this->em->createQueryBuilder()->select('COUNT(t.id)')->from(Tender::class, 't')
            ->where('t.status = :s AND t.deadline > :now')
            ->andWhere('SIZE(t.invitedSuppliers) = 0 OR EXISTS (SELECT t2.id FROM '.Tender::class.' t2 JOIN t2.invitedSuppliers inv WHERE t2 = t AND inv.id = :org)')
            ->andWhere('NOT EXISTS (SELECT p.id FROM '.Proposal::class.' p WHERE p.tender = t AND p.supplier = :org)')
            ->setParameter('s', Tender::STATUS_PUBLISHED)->setParameter('now', new \DateTimeImmutable())->setParameter('org', $org->getId())
            ->getQuery()->getSingleScalarResult();
        $inProgress = $this->count(Proposal::class, 'e', 'e.supplier = :org AND e.status IN (:s)', ['org' => $org, 's' => [Proposal::STATUS_SUBMITTED, Proposal::STATUS_SHORTLISTED]]);
        $won = $this->count(Proposal::class, 'e', 'e.supplier = :org AND e.status = :s', ['org' => $org, 's' => Proposal::STATUS_ACCEPTED]);
        $lost = $this->count(Proposal::class, 'e', 'e.supplier = :org AND e.status = :s', ['org' => $org, 's' => Proposal::STATUS_REJECTED]);
        $activeContracts = $this->count(Contract::class, 'e', 'e.supplier = :org AND e.status = :s', ['org' => $org, 's' => Contract::STATUS_ACTIVE]);
        $toSign = $this->count(Contract::class, 'e', 'e.supplier = :org AND e.status = :s', ['org' => $org, 's' => Contract::STATUS_PENDING_SUPPLIER_SIGNATURE]);
        $revenueYtd = $this->sum(Invoice::class, 'e.amountExclTaxCents', 'e.supplier = :org AND e.status = :s AND e.paidAt >= :from', ['org' => $org, 's' => Invoice::STATUS_PAID, 'from' => $yearStart]) / 100;
        $outstanding = $this->sum(Invoice::class, 'e.amountInclTaxCents', 'e.supplier = :org AND e.status IN (:s)', ['org' => $org, 's' => [Invoice::STATUS_SUBMITTED, Invoice::STATUS_APPROVED]]) / 100;
        $rejectedInvoices = $this->count(Invoice::class, 'e', 'e.supplier = :org AND e.status = :s', ['org' => $org, 's' => Invoice::STATUS_REJECTED]);

        $d->kpis = [
            $this->kpi('opportunities', 'Opportunités ouvertes', $opportunities),
            $this->kpi('proposalsInProgress', 'Propositions en cours', $inProgress),
            $this->kpi('winRate', 'Taux de transformation', $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : 0, 'percent'),
            $this->kpi('activeContracts', 'Contrats actifs', $activeContracts),
            $this->kpi('revenueYtd', 'CA encaissé (année)', $revenueYtd, 'currency'),
            $this->kpi('outstanding', 'Encours à percevoir (TTC)', $outstanding, 'currency'),
            $this->kpi('averageRating', 'Note moyenne', $org->getAverageRating() ?? 0, 'rating'),
            $this->kpi('contractsToSign', 'Contrats à signer', $toSign),
        ];
        $d->monthly = $this->monthly('e.supplier = :org', ['org' => $org]);
        $d->statusBreakdown = [
            'proposals' => $this->breakdown(Proposal::class, 'e.supplier = :org', ['org' => $org]),
            'contracts' => $this->breakdown(Contract::class, 'e.supplier = :org', ['org' => $org]),
            'invoices' => $this->breakdown(Invoice::class, 'e.supplier = :org', ['org' => $org]),
        ];
        $d->topPartners = $this->topPartners('e.supplier = :org', ['org' => $org], 'buyer');
        $d->actionItems = array_values(array_filter([
            $opportunities ? ['type' => 'tender', 'label' => "$opportunities opportunité(s) sans réponse", 'link' => '/tenders'] : null,
            $toSign ? ['type' => 'contract', 'label' => "$toSign contrat(s) à signer", 'link' => '/contracts?status=pending_supplier_signature'] : null,
            $rejectedInvoices ? ['type' => 'invoice', 'label' => "$rejectedInvoices facture(s) refusée(s) à corriger", 'link' => '/invoices?status=rejected'] : null,
        ]));

        return $d;
    }

    private function admin(): Dashboard
    {
        $d = new Dashboard();
        $d->space = 'admin';
        $d->kpis = [
            $this->kpi('buyers', 'Clients acheteurs', $this->count(Organization::class, 'e', 'e.type = :t', ['t' => Organization::TYPE_BUYER])),
            $this->kpi('suppliers', 'Fournisseurs', $this->count(Organization::class, 'e', 'e.type = :t', ['t' => Organization::TYPE_SUPPLIER])),
            $this->kpi('users', 'Utilisateurs actifs', $this->count(User::class, 'e', 'e.active = true', [])),
            $this->kpi('publishedTenders', 'AO publiés', $this->count(Tender::class, 'e', 'e.status = :s', ['s' => Tender::STATUS_PUBLISHED])),
            $this->kpi('proposals', 'Propositions reçues', $this->count(Proposal::class, 'e', '1 = 1', [])),
            $this->kpi('activeContracts', 'Contrats actifs', $this->count(Contract::class, 'e', 'e.status = :s', ['s' => Contract::STATUS_ACTIVE])),
            $this->kpi('gmv', 'Volume facturé payé', $this->sum(Invoice::class, 'e.amountExclTaxCents', 'e.status = :s', ['s' => Invoice::STATUS_PAID]) / 100, 'currency'),
        ];
        $d->monthly = $this->monthly('1 = 1', []);
        $d->statusBreakdown = [
            'tenders' => $this->breakdown(Tender::class, '1 = 1', []),
            'contracts' => $this->breakdown(Contract::class, '1 = 1', []),
            'invoices' => $this->breakdown(Invoice::class, '1 = 1', []),
        ];
        $d->topPartners = $this->topPartners('1 = 1', [], 'supplier');

        return $d;
    }

    /** @return array{key: string, label: string, value: float|int, format: string} */
    private function kpi(string $key, string $label, float|int $value, string $format = 'number'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => $format];
    }

    /** @param array<string, mixed> $params */
    private function qb(string $class, string $select, string $where, array $params): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select($select)->from($class, 'e')->where($where);
        foreach ($params as $k => $v) {
            $qb->setParameter($k, $v);
        }

        return $qb;
    }

    /** @param array<string, mixed> $params */
    private function count(string $class, string $alias, string $where, array $params): int
    {
        return (int) $this->qb($class, 'COUNT(e.id)', $where, $params)->getQuery()->getSingleScalarResult();
    }

    /** @param array<string, mixed> $params */
    private function sum(string $class, string $field, string $where, array $params): float
    {
        return (float) $this->qb($class, "COALESCE(SUM($field), 0)", $where, $params)->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, int>
     */
    private function breakdown(string $class, string $where, array $params): array
    {
        $rows = $this->qb($class, 'e.status AS status, COUNT(e.id) AS total', $where, $params)->groupBy('e.status')->getQuery()->getArrayResult();

        return array_column(array_map(static fn ($r) => ['s' => $r['status'], 'n' => (int) $r['total']], $rows), 'n', 's');
    }

    /**
     * Approved or paid invoice amounts per billing period over the last 12 months (gaps filled with 0).
     *
     * @param array<string, mixed> $params
     *
     * @return list<array{period: string, amount: float}>
     */
    private function monthly(string $where, array $params): array
    {
        $from = (new \DateTimeImmutable('first day of this month'))->modify(\sprintf('-%d months', self::MONTHS - 1));
        $rows = $this->qb(Invoice::class, 'e.period AS period, SUM(e.amountExclTaxCents) AS total', $where.' AND e.status IN (:billed) AND e.period >= :fromPeriod', $params + [
            'billed' => [Invoice::STATUS_APPROVED, Invoice::STATUS_PAID],
            'fromPeriod' => $from->format('Y-m'),
        ])->groupBy('e.period')->getQuery()->getArrayResult();
        $byPeriod = array_column($rows, 'total', 'period');

        $series = [];
        for ($i = 0; $i < self::MONTHS; ++$i) {
            $period = $from->modify("+$i months")->format('Y-m');
            $series[] = ['period' => $period, 'amount' => round(((int) ($byPeriod[$period] ?? 0)) / 100, 2)];
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array{id: int, name: string, amount: float, rating: float|null}>
     */
    private function topPartners(string $where, array $params, string $side): array
    {
        $rows = $this->qb(Invoice::class, "o.id AS id, o.name AS name, o.averageRating AS rating, SUM(e.amountExclTaxCents) AS total", $where.' AND e.status IN (:billed)', $params + [
            'billed' => [Invoice::STATUS_APPROVED, Invoice::STATUS_PAID],
        ])->join("e.$side", 'o')->groupBy('o.id, o.name, o.averageRating')->orderBy('total', 'DESC')->setMaxResults(5)->getQuery()->getArrayResult();

        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'amount' => round(((int) $r['total']) / 100, 2),
            'rating' => null !== $r['rating'] ? (float) $r['rating'] : null,
        ], $rows);
    }
}
