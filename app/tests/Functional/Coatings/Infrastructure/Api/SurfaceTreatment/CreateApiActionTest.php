<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Api\SurfaceTreatment;

use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class CreateApiActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $userPassword;

    /** @var list<Uuid> */
    private array $treatmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $this->userEmail = 'test_st_api_create_'.$suffix.'@example.com';
        $this->userPassword = 'test_api_password_'.$suffix;

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword($this->userPassword, $hasher);
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);
        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setValue($user, ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->treatmentIds as $id) {
                $t = $em->find(SurfaceTreatment::class, $id);
                if (null !== $t) {
                    $em->remove($t);
                }
            }

            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if (null !== $user) {
                $em->remove($user);
            }

            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    private function loginAndGetToken(): string
    {
        $this->client->request(
            'POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $this->userEmail, 'password' => $this->userPassword]),
        );

        $response = json_decode($this->client->getResponse()->getContent(), true);

        return $response['data']['token'] ?? '';
    }

    public function test_post_valid_body_returns_201_with_treatment_data(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $suffix = bin2hex(random_bytes(3));

        $this->client->request(
            'POST',
            '/api/surface-treatments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'description' => 'Дробеструйная очистка Sa 2½ — api test '.$suffix,
                'code' => 'Sa2½',
                'standardCode' => 'ISO 8501-1',
                'substrateScope' => ['steel_carbon', 'steel_galvanized'],
            ]),
        );

        self::assertResponseStatusCodeSame(201);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $body['result']);

        $data = $body['data'];
        self::assertNotEmpty($data['id']);
        self::assertStringContainsString($suffix, $data['description']);
        self::assertContains('steel_carbon', $data['substrateScope']);

        $this->treatmentIds[] = Uuid::fromString($data['id']);
    }

    public function test_post_invalid_substrate_returns_400(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request(
            'POST',
            '/api/surface-treatments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'description' => 'Очистка с неверной подложкой',
                'substrateScope' => ['INVALID_SUBSTRATE_VALUE'],
            ]),
        );

        self::assertResponseStatusCodeSame(400);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('error', $body['result']);
    }

    public function test_post_without_description_returns_400(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request(
            'POST',
            '/api/surface-treatments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'substrateScope' => ['STEEL_CARBON'],
            ]),
        );

        self::assertResponseStatusCodeSame(400);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('error', $body['result']);
    }

    public function test_post_without_auth_returns_401(): void
    {
        $this->client->request(
            'POST',
            '/api/surface-treatments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'description' => 'Тест без авторизации',
                'substrateScope' => ['STEEL_CARBON'],
            ]),
        );

        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [401, 302],
        );
    }
}
