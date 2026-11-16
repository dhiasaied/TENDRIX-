<?php

namespace App\Tests\Api;

use App\Entity\Contract;
use App\Entity\Organization;
use App\Entity\Proposal;
use App\Entity\Tender;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regression tests for the security audit: session handling, field-level confidentiality,
 * strict role checks and data-protection guarantees.
 */
final class SecurityHardeningTest extends ApiTestCase
{
    // ---------- Session & authentication ----------

    public function testLoginSetsHardenedCookieAndKeepsTokenOutOfTheBody(): void
    {
        $response = static::createClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'claire.dubois@horizon.test', 'password' => 'Demo!Tendrix2026']]);
        self::assertStatus(200, $response);

        $body = $response->toArray();
        self::assertArrayNotHasKey('token', $body, 'The JWT must not be readable by JavaScript.');
        self::assertSame('claire.dubois@horizon.test', $body['user']['email']);

        $cookie = implode("\n", $response->getHeaders()['set-cookie'] ?? []);
        self::assertStringContainsString('BEARER=', $cookie);
        self::assertStringContainsStringIgnoringCase('httponly', $cookie);
        self::assertStringContainsStringIgnoringCase('samesite=strict', $cookie);
    }

    public function testLogoutRevokesTheSession(): void
    {
        $client = $this->clientAs('claire.dubois@horizon.test');
        $token = $this->tokenOf($client);

        self::assertStatus(204, $client->request('POST', '/api/auth/logout'));

        // Even a copy of the old token is now useless.
        $replay = static::createClient()->request('GET', '/api/me', ['headers' => ['Authorization' => 'Bearer '.$token]]);
        self::assertStatus(401, $replay);
    }

    public function testPasswordChangeRequiresCurrentPasswordAndRevokesOtherSessions(): void
    {
        $client = $this->clientAs('paul.bernard@horizon.test');
        $oldToken = $this->tokenOf($client);
        $me = $client->request('GET', '/api/me')->toArray();

        $patch = fn (array $json) => $client->request('PATCH', '/api/users/'.$me['id'], ['json' => $json, 'headers' => ['Content-Type' => 'application/merge-patch+json']]);

        self::assertStatus(422, $patch(['password' => 'N3w-Passphrase!2026']), 'Current password is mandatory.');
        self::assertStatus(422, $patch(['password' => 'N3w-Passphrase!2026', 'currentPassword' => 'wrong']));
        self::assertStatus(200, $patch(['password' => 'N3w-Passphrase!2026', 'currentPassword' => 'Demo!Tendrix2026']));

        $replay = static::createClient()->request('GET', '/api/me', ['headers' => ['Authorization' => 'Bearer '.$oldToken]]);
        self::assertStatus(401, $replay, 'Tokens issued before a password change are revoked.');

        $this->clientAs('paul.bernard@horizon.test', 'N3w-Passphrase!2026');
    }

    public function testDeactivationRevokesSessionsImmediately(): void
    {
        $supplier = $this->clientAs('bd@datanext.test');
        $admin = $this->clientAs('admin@tendrix.test');
        $userId = $this->entity(User::class, ['email' => 'bd@datanext.test'])->getId();

        self::assertStatus(200, $admin->request('PATCH', '/api/users/'.$userId, ['json' => ['active' => false], 'headers' => ['Content-Type' => 'application/merge-patch+json']]));
        self::assertStatus(401, $supplier->request('GET', '/api/me'));
    }

    public function testWeakPasswordsAreRejectedAtRegistration(): void
    {
        $response = static::createClient()->request('POST', '/api/auth/register', ['json' => [
            'companyName' => 'Weak Co', 'firstName' => 'A', 'lastName' => 'B', 'email' => 'weak@weak.test', 'password' => 'password12',
        ]]);
        self::assertStatus(422, $response);
    }

    public function testRegistrationIsRateLimited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $last = null;
        for ($i = 0; $i < 6; ++$i) {
            $last = $client->request('POST', '/api/auth/register', ['json' => ['companyName' => '']]);
        }
        self::assertStatus(429, $last);
    }

    // ---------- Field-level confidentiality ----------

    public function testSuppliersDoNotSeeBuyerInternalTenderData(): void
    {
        $iri = $this->iriOf(Tender::class, ['reference' => 'AO-2026-0004']);
        $tender = $this->clientAs('bd@datanext.test')->request('GET', $iri)->toArray();

        foreach (['suggestedDailyRate', 'statusComment', 'proposalsCount', 'invitedSuppliers', 'createdBy', 'approvedBy'] as $field) {
            self::assertArrayNotHasKey($field, $tender, "Suppliers must not see '$field'.");
        }
        self::assertArrayHasKey('budgetMax', $tender);

        $buyerView = $this->clientAs('claire.dubois@horizon.test')->request('GET', $iri)->toArray();
        self::assertArrayHasKey('suggestedDailyRate', $buyerView);
        self::assertArrayHasKey('proposalsCount', $buyerView);
    }

    public function testEmailsAreNotExposedAcrossOrganizations(): void
    {
        $iri = $this->iriOf(Tender::class, ['reference' => 'AO-2026-0001']);
        $proposals = $this->clientAs('claire.dubois@horizon.test')->request('GET', '/api/proposals', ['query' => ['tender' => $iri]])->toArray()['member'];

        self::assertNotEmpty($proposals);
        self::assertArrayNotHasKey('email', $proposals[0]['submittedBy'], 'Supplier staff emails are not shared with buyers.');
    }

    public function testSuppliersDoNotSeeTheirAiScoring(): void
    {
        $proposal = $this->entity(Proposal::class, ['candidateName' => 'Camille Roux']);
        $data = $this->clientAs('contact@digitalcraft.test')->request('GET', '/api/proposals/'.$proposal->getId())->toArray();

        foreach (['matchScore', 'matchBreakdown', 'matchAnalysis'] as $field) {
            self::assertArrayNotHasKey($field, $data, "Suppliers must not see '$field'.");
        }
    }

    // ---------- Strict role checks ----------

    public function testRequestersCannotAccessInvoices(): void
    {
        self::assertStatus(403, $this->clientAs('paul.bernard@horizon.test')->request('GET', '/api/invoices'));
    }

    public function testSuppliersCannotQueryMarketPricing(): void
    {
        $response = $this->clientAs('bd@datanext.test')->request('POST', '/api/ai/price-suggestion', ['json' => ['skills' => ['PHP'], 'seniority' => 'senior']]);
        self::assertStatus(403, $response);
    }

    public function testAdminsCannotLockThemselvesOut(): void
    {
        $admin = $this->clientAs('admin@tendrix.test');
        $id = $this->entity(User::class, ['email' => 'admin@tendrix.test'])->getId();
        $patch = fn (array $json) => $admin->request('PATCH', '/api/users/'.$id, ['json' => $json, 'headers' => ['Content-Type' => 'application/merge-patch+json']]);

        self::assertStatus(422, $patch(['active' => false]));
        self::assertStatus(422, $patch(['roles' => ['ROLE_BUYER']]));
    }

    public function testRolesMustMatchTheOrganizationType(): void
    {
        $response = $this->clientAs('admin@tendrix.test')->request('POST', '/api/users', ['json' => [
            'email' => 'mixed@horizon.test', 'firstName' => 'Mixed', 'lastName' => 'Role', 'password' => 'Str0ng-Passphrase!26',
            'roles' => ['ROLE_SUPPLIER'], 'organization' => $this->iriOf(Organization::class, ['name' => 'Horizon Assurances']),
        ]]);
        self::assertStatus(422, $response);
    }

    public function testRecordsWithHistoryCannotBeHardDeleted(): void
    {
        $admin = $this->clientAs('admin@tendrix.test');
        $userId = $this->entity(User::class, ['email' => 'claire.dubois@horizon.test'])->getId();
        $orgId = $this->entity(Organization::class, ['name' => 'Horizon Assurances'])->getId();

        self::assertStatus(405, $admin->request('DELETE', '/api/users/'.$userId));
        self::assertStatus(405, $admin->request('DELETE', '/api/organizations/'.$orgId));
    }

    public function testDuplicateCandidateIsAValidationErrorNotAServerError(): void
    {
        $supplier = $this->clientAs('sales@cloudforge.test');
        $tender = $this->iriOf(Tender::class, ['reference' => 'AO-2026-0005']);

        $response = $supplier->request('POST', '/api/proposals', ['json' => [
            'tender' => $tender, 'candidateName' => 'Lina Mansour', 'candidateTitle' => 'Dev', 'candidateSkills' => ['Angular'],
            'experienceYears' => 4, 'dailyRate' => 500, 'availableFrom' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'candidateSummary' => str_repeat('Développeuse Angular expérimentée. ', 3),
        ]]);
        self::assertStatus(422, $response);
    }

    // ---------- Data protection & transport ----------

    public function testApiResponsesAreNotCacheableAndCarrySecurityHeaders(): void
    {
        $response = $this->clientAs('claire.dubois@horizon.test')->request('GET', '/api/me');
        $headers = $response->getHeaders();

        self::assertStringContainsString('no-store', $headers['cache-control'][0]);
        self::assertSame('nosniff', $headers['x-content-type-options'][0] ?? null);
        self::assertSame('DENY', $headers['x-frame-options'][0] ?? null);
    }

    public function testAdminChangesToUsersAreAudited(): void
    {
        $admin = $this->clientAs('admin@tendrix.test');
        $userId = $this->entity(User::class, ['email' => 'sales@cloudforge.test'])->getId();
        $admin->request('PATCH', '/api/users/'.$userId, ['json' => ['active' => false], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);

        $logs = $admin->request('GET', '/api/audit_logs', ['query' => ['entityType' => 'user', 'entityId' => $userId]])->toArray()['member'];
        $actions = array_column($logs, 'action');
        self::assertContains('user.updated', $actions);
        $entry = $logs[array_search('user.updated', $actions, true)];
        self::assertSame('admin@tendrix.test', $entry['actorEmail']);
        self::assertArrayHasKey('active', $entry['payload']['changes']);
        self::assertArrayNotHasKey('password', $entry['payload']['changes'], 'Secrets never reach the audit log.');
    }

    private function tokenOf(\ApiPlatform\Symfony\Bundle\Test\Client $client): string
    {
        $cookie = $client->getCookieJar()->get('BEARER', '/api');
        self::assertNotNull($cookie, 'Session cookie missing.');

        return $cookie->getValue();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return T
     */
    private function entity(string $class, array $criteria): object
    {
        $entity = self::getContainer()->get(EntityManagerInterface::class)->getRepository($class)->findOneBy($criteria);
        self::assertNotNull($entity);

        return $entity;
    }
}
