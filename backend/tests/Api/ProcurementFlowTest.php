<?php

namespace App\Tests\Api;

use App\Entity\Contract;
use App\Entity\Notification;
use App\Entity\Tender;
use Doctrine\ORM\EntityManagerInterface;

/**
 * End-to-end lifecycle: tender -> approval -> proposal -> AI scoring -> award -> contract -> invoice -> payment.
 */
final class ProcurementFlowTest extends ApiTestCase
{
    public function testFullProcurementLifecycle(): void
    {
        $requester = $this->clientAs('paul.bernard@horizon.test');
        $buyer = $this->clientAs('claire.dubois@horizon.test');
        $supplier = $this->clientAs('contact@digitalcraft.test');
        $transition = static fn ($client, string $iri, string $name, ?string $comment = null) => $client->request('POST', $iri.'/transition', ['json' => ['transition' => $name, 'comment' => $comment]]);

        // 1. A requester drafts a tender and submits it for approval.
        $response = $requester->request('POST', '/api/tenders', ['json' => [
            'title' => 'Développeur Symfony confirmé',
            'description' => 'Développement des API de gestion des contrats d\'assurance avec API Platform et PostgreSQL.',
            'skills' => ['PHP', 'Symfony', 'PostgreSQL'],
            'seniority' => 'confirmed',
            'location' => 'Paris',
            'remotePolicy' => 'hybrid',
            'startDate' => (new \DateTimeImmutable('+2 months'))->format('Y-m-d'),
            'estimatedDays' => 60,
            'budgetMax' => 600,
            'deadline' => (new \DateTimeImmutable('+1 month'))->format(\DATE_ATOM),
        ]]);
        self::assertStatus(201, $response);
        $tender = $response->toArray();
        self::assertSame('draft', $tender['status']);
        self::assertMatchesRegularExpression('/^AO-\d{4}-\d{4}$/', $tender['reference']);
        self::assertGreaterThan(0, $tender['suggestedDailyRate']);

        self::assertStatus(403, $transition($requester, $tender['@id'], 'publish'), 'Requesters cannot publish.');
        self::assertStatus(200, $transition($requester, $tender['@id'], 'submit'));

        // 2. The buyer publishes it.
        $published = $transition($buyer, $tender['@id'], 'publish')->toArray();
        self::assertSame('published', $published['status']);
        self::assertSame('Claire Dubois', $published['approvedBy']['fullName']);

        // 3. A supplier answers; AI scoring runs on the (sync in tests) message bus.
        $response = $supplier->request('POST', '/api/proposals', ['json' => [
            'tender' => $tender['@id'],
            'candidateName' => 'Nour Ayari',
            'candidateTitle' => 'Développeuse Symfony',
            'candidateSkills' => ['PHP 8', 'Symfony 7', 'PostgreSQL', 'Docker'],
            'experienceYears' => 4,
            'candidateSummary' => 'Développeuse back-end Symfony depuis 4 ans, expérience des API REST et API Platform dans le secteur de l\'assurance.',
            'dailyRate' => 560,
            'availableFrom' => (new \DateTimeImmutable('+6 weeks'))->format('Y-m-d'),
        ]]);
        self::assertStatus(201, $response);
        $proposal = $response->toArray();

        $scored = $buyer->request('GET', $proposal['@id'])->toArray();
        self::assertSame(100, $scored['matchScore']);
        self::assertSame(['skills' => 45, 'experience' => 20, 'rate' => 20, 'availability' => 15], $scored['matchBreakdown']);

        // 4. Selection: accept requires a shortlist first, and only buyers may accept.
        self::assertStatus(409, $transition($buyer, $proposal['@id'], 'accept'));
        self::assertStatus(422, $transition($buyer, $proposal['@id'], 'reject'), 'Rejections need a reason.');
        self::assertStatus(200, $transition($requester, $proposal['@id'], 'shortlist'));
        self::assertStatus(403, $transition($requester, $proposal['@id'], 'accept'));
        self::assertStatus(200, $transition($buyer, $proposal['@id'], 'accept'));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tenderEntity = $em->getRepository(Tender::class)->findOneBy(['reference' => $tender['reference']]);
        self::assertSame(Tender::STATUS_AWARDED, $tenderEntity->getStatus());
        $contract = $em->getRepository(Contract::class)->findOneBy(['tender' => $tenderEntity]);
        self::assertNotNull($contract);
        self::assertSame(560, $contract->getDailyRate());
        $contractIri = '/api/contracts/'.$contract->getId();

        // 5. Signatures in order: buyer first, then supplier.
        self::assertStatus(409, $transition($supplier, $contractIri, 'sign_supplier'));
        self::assertStatus(200, $transition($buyer, $contractIri, 'sign_buyer'));
        $active = $transition($supplier, $contractIri, 'sign_supplier')->toArray();
        self::assertSame('active', $active['status']);

        // 6. The contract starts in the future, so the current month cannot be invoiced yet.
        $response = $supplier->request('POST', '/api/invoices', ['json' => ['contract' => $contractIri, 'period' => (new \DateTimeImmutable())->format('Y-m'), 'daysWorked' => 5]]);
        self::assertStatus(422, $response);

        self::assertGreaterThanOrEqual(1, $em->getRepository(Notification::class)->count(['type' => 'proposal.accepted']));
    }

    public function testInvoiceRejectionCorrectionAndPayment(): void
    {
        $supplier = $this->clientAs('contact@digitalcraft.test');
        $buyer = $this->clientAs('claire.dubois@horizon.test');
        $contractIri = $this->iriOf(Contract::class, ['reference' => 'CT-2026-0001']);
        $period = (new \DateTimeImmutable())->format('Y-m');
        $transition = static fn ($client, string $iri, string $name, ?string $comment = null) => $client->request('POST', $iri.'/transition', ['json' => ['transition' => $name, 'comment' => $comment]]);

        $response = $supplier->request('POST', '/api/invoices', ['json' => ['contract' => $contractIri, 'period' => $period, 'daysWorked' => 10]]);
        self::assertStatus(201, $response);
        $invoice = $response->toArray();
        self::assertSame(6400.0, (float) $invoice['amountExclTax']);
        self::assertSame(7680.0, (float) $invoice['amountInclTax']);

        self::assertStatus(404, $buyer->request('GET', $invoice['@id']), 'Buyers never see supplier drafts.');
        self::assertStatus(422, $supplier->request('POST', '/api/invoices', ['json' => ['contract' => $contractIri, 'period' => $period, 'daysWorked' => 1]]), 'One invoice per period.');

        self::assertStatus(200, $transition($supplier, $invoice['@id'], 'submit'));
        self::assertStatus(422, $transition($buyer, $invoice['@id'], 'reject'), 'Rejection requires a reason.');
        self::assertStatus(200, $transition($buyer, $invoice['@id'], 'reject', 'CRA manquant'));

        $response = $supplier->request('PATCH', $invoice['@id'], ['json' => ['daysWorked' => 9.5], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        self::assertStatus(200, $response);
        self::assertStatus(200, $transition($supplier, $invoice['@id'], 'submit'));
        self::assertStatus(200, $transition($buyer, $invoice['@id'], 'approve'));
        $paid = $transition($buyer, $invoice['@id'], 'pay')->toArray();

        self::assertSame('paid', $paid['status']);
        self::assertSame(6080.0, (float) $paid['amountExclTax']);
    }

    public function testSupplierRegistration(): void
    {
        $response = static::createClient()->request('POST', '/api/auth/register', ['json' => [
            'companyName' => 'Kodera Labs',
            'firstName' => 'Rania',
            'lastName' => 'Gharbi',
            'email' => 'rania@kodera.test',
            'password' => 'Kodera!Strong2026pass',
            'skills' => ['React'],
        ]]);
        self::assertStatus(201, $response);

        $me = $this->clientAs('rania@kodera.test', 'Kodera!Strong2026pass')->request('GET', '/api/me')->toArray();
        self::assertSame('supplier', $me['organization']['type']);
        self::assertContains('ROLE_SUPPLIER', $me['roles']);

        $duplicate = static::createClient()->request('POST', '/api/auth/register', ['json' => [
            'companyName' => 'Kodera Labs', 'firstName' => 'X', 'lastName' => 'Y', 'email' => 'rania@kodera.test', 'password' => 'Kodera!Strong2026pass',
        ]]);
        self::assertStatus(422, $duplicate);
    }

    public function testDashboardsAreRoleAware(): void
    {
        $buyer = $this->clientAs('claire.dubois@horizon.test')->request('GET', '/api/reports/dashboard')->toArray();
        self::assertSame('buyer', $buyer['space']);
        self::assertCount(12, $buyer['monthly']);

        $supplier = $this->clientAs('bd@datanext.test')->request('GET', '/api/reports/dashboard')->toArray();
        self::assertSame('supplier', $supplier['space']);
        self::assertContains('winRate', array_column($supplier['kpis'], 'key'));
    }
}
