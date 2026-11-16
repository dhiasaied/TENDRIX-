<?php

namespace App\Tests\Api;

use App\Entity\Contract;
use App\Entity\Tender;

final class SecurityTest extends ApiTestCase
{
    public function testAnonymousAccessIsRejected(): void
    {
        self::assertStatus(401, static::createClient()->request('GET', '/api/tenders'));
    }

    public function testInvalidCredentials(): void
    {
        $response = static::createClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'claire.dubois@horizon.test', 'password' => 'wrong']]);
        self::assertStatus(401, $response);
    }

    public function testLoginReturnsSessionProfileAndAMinimalToken(): void
    {
        $client = static::createClient();
        $response = $client->request('POST', '/api/auth/login', ['json' => ['email' => 'claire.dubois@horizon.test', 'password' => 'Demo!Tendrix2026']]);
        $session = $response->toArray()['user'];
        self::assertSame('Horizon Assurances', $session['organization']['name']);
        self::assertContains('ROLE_BUYER', $session['roles']);

        $token = (string) $client->getCookieJar()->get('BEARER', '/api')?->getValue();
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true);
        self::assertSame('claire.dubois@horizon.test', $payload['email']);
        self::assertArrayHasKey('sv', $payload);
        self::assertArrayNotHasKey('organization', $payload, 'The token carries no business data.');
    }

    public function testBuyerOnlySeesOwnTenders(): void
    {
        $data = $this->clientAs('karim.saidi@nova.test')->request('GET', '/api/tenders')->toArray();

        self::assertGreaterThan(0, $data['totalItems']);
        foreach ($data['member'] as $tender) {
            self::assertSame('Nova Retail Group', $tender['organization']['name']);
        }
    }

    public function testOtherOrganizationsTenderIsNotFound(): void
    {
        $client = $this->clientAs('karim.saidi@nova.test');
        $iri = $this->iriOf(Tender::class, ['reference' => 'AO-2026-0001']); // Horizon's tender

        self::assertStatus(404, $client->request('GET', $iri));
    }

    public function testRestrictedConsultationIsHiddenFromUninvitedSuppliers(): void
    {
        $references = array_column($this->clientAs('bd@datanext.test')->request('GET', '/api/tenders')->toArray()['member'], 'reference');

        self::assertContains('AO-2026-0004', $references, 'Open published tender must be visible.');
        self::assertNotContains('AO-2026-0005', $references, 'Restricted consultation must stay hidden.');
        self::assertNotContains('AO-2026-0008', $references, 'Drafts must stay hidden.');
    }

    public function testSupplierCannotCreateTender(): void
    {
        self::assertStatus(403, $this->clientAs('bd@datanext.test')->request('POST', '/api/tenders', ['json' => ['title' => 'Hack']]));
    }

    public function testSupplierCannotSeeAnotherSuppliersContract(): void
    {
        $client = $this->clientAs('bd@datanext.test');
        self::assertStatus(404, $client->request('GET', $this->iriOf(Contract::class, ['reference' => 'CT-2026-0001'])));
    }

    public function testOnlyAdminsReadTheAuditLog(): void
    {
        self::assertStatus(403, $this->clientAs('claire.dubois@horizon.test')->request('GET', '/api/audit_logs'));
        self::assertStatus(200, $this->clientAs('admin@tendrix.test')->request('GET', '/api/audit_logs'));
    }

    public function testUserCannotEscalateOwnRoles(): void
    {
        $client = $this->clientAs('paul.bernard@horizon.test');
        $me = $client->request('GET', '/api/me')->toArray();

        $response = $client->request('PATCH', '/api/users/'.$me['id'], [
            'json' => ['roles' => ['ROLE_ADMIN'], 'jobTitle' => 'Directeur'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertStatus(200, $response);

        $after = $client->request('GET', '/api/me')->toArray();
        self::assertSame('Directeur', $after['jobTitle']);
        self::assertNotContains('ROLE_ADMIN', $after['roles']);
    }
}
