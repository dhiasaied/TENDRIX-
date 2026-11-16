<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase as BaseApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\AppFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Functional tests against a fresh SQLite database seeded with the demo fixtures.
 */
abstract class ApiTestCase extends BaseApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private static bool $schemaCreated = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        if (!self::$schemaCreated) {
            $tool = new SchemaTool($em);
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $tool->dropDatabase();
            $tool->createSchema($metadata);
            self::$schemaCreated = true;
        }

        self::getContainer()->get('cache.test_registration_limiter')->clear();

        $loader = new Loader();
        $loader->addFixture(self::getContainer()->get(AppFixtures::class));
        (new ORMExecutor($em, new ORMPurger($em)))->execute($loader->getFixtures());
        $em->clear();
        self::ensureKernelShutdown();
    }

    /**
     * Asserts on a given response. The framework's assertResponse* helpers always inspect the
     * most recently created client, which is wrong when a test drives several users.
     */
    protected static function assertStatus(int $expected, ResponseInterface $response, string $message = ''): void
    {
        self::assertSame($expected, $response->getStatusCode(), trim($message.' '.substr($response->getContent(false), 0, 600)));
    }

    /**
     * Logs in like the browser does: the session travels in the httpOnly BEARER cookie kept by the client's cookie jar.
     */
    protected function clientAs(string $email, string $password = AppFixtures::PASSWORD): Client
    {
        $client = static::createClient();
        $response = $client->request('POST', '/api/auth/login', ['json' => ['email' => $email, 'password' => $password]]);
        self::assertStatus(200, $response);
        $client->setDefaultOptions(['headers' => ['Accept' => 'application/ld+json']]);

        return $client;
    }

    protected function iriOf(string $class, array $criteria): string
    {
        $entity = self::getContainer()->get(EntityManagerInterface::class)->getRepository($class)->findOneBy($criteria);
        self::assertNotNull($entity, "No $class matching ".json_encode($criteria));

        return self::getContainer()->get('api_platform.iri_converter')->getIriFromResource($entity);
    }
}
