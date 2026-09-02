<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Tests\Support\CsrfTestHelper;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class CreateInlineActionTest extends WebTestCase
{
    private const ENDPOINT = '/cabinet/coating/surface-treatment/create-inline';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminEmail;
    private string $userEmail;

    /** @var string[] */
    private array $createdTreatmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->adminEmail = 'test_ci_admin_'.$suffix.'@example.com';
        $this->userEmail = 'test_ci_user_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = new User(new Email($this->adminEmail));
        $admin->setPassword('test_password', $hasher);

        $refActive = new \ReflectionProperty($admin, 'isActive');
        $refActive->setAccessible(true);
        $refActive->setValue($admin, true);

        $refRoles = new \ReflectionProperty($admin, 'roles');
        $refRoles->setAccessible(true);
        $refRoles->setValue($admin, ['ROLE_ADMIN']);

        $this->em->persist($admin);

        $regularUser = new User(new Email($this->userEmail));
        $regularUser->setPassword('test_password', $hasher);

        $refActive2 = new \ReflectionProperty($regularUser, 'isActive');
        $refActive2->setAccessible(true);
        $refActive2->setValue($regularUser, true);

        $this->em->persist($regularUser);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdTreatmentIds as $id) {
                $treatment = $em->find(SurfaceTreatment::class, Uuid::fromString($id));
                if (null !== $treatment) {
                    $em->remove($treatment);
                }
            }

            foreach ([$this->adminEmail, $this->userEmail] as $email) {
                $user = $em->getRepository(User::class)->findOneBy(['email.value' => $email]);
                if (null !== $user) {
                    $em->remove($user);
                }
            }

            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_non_admin_gets_403(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regularUser = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
        $this->client->loginUser($regularUser);
        CsrfTestHelper::enable($this->client);

        $this->client->request(
            'POST',
            self::ENDPOINT,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'description' => 'Песок 403',
                'substrateScope' => [Substrate::STEEL_CARBON->value],
            ]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_admin_creates_treatment_and_returns_201(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email.value' => $this->adminEmail]);
        $this->client->loginUser($admin);
        CsrfTestHelper::enable($this->client);

        $suffix = uniqid('', true);
        $description = 'Пескоструйная '.$suffix;

        $this->client->request(
            'POST',
            self::ENDPOINT,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'description' => $description,
                'code' => 'Sa2.'.$suffix,
                'standardCode' => 'ISO 8501-1',
                'substrateScope' => [Substrate::STEEL_CARBON->value],
            ]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        // ResponseListener оборачивает JsonResponse в {result, status, data, message}
        $data = $body['data'] ?? $body;
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('description', $data);
        self::assertSame($description, $data['description']);

        $this->createdTreatmentIds[] = $data['id'];
    }

    public function test_missing_description_returns_422_json_with_message(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email.value' => $this->adminEmail]);
        $this->client->loginUser($admin);
        CsrfTestHelper::enable($this->client);

        $this->client->request(
            'POST',
            self::ENDPOINT,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'description' => '',
                'substrateScope' => [Substrate::STEEL_CARBON->value],
            ]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        // ResponseListener оборачивает ошибочные ответы в {result, status, data, message}
        $message = $body['message'] ?? null;
        self::assertNotEmpty($message);
    }
}
