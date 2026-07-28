<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class SuggestActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $treatmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_st_suggest_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setAccessible(true);
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);

        $shortSuffix = substr($suffix, -6);
        $treatment = new SurfaceTreatment(
            Uuid::v7(),
            'Пескоструйная очистка suggest '.$suffix,
            'Sa2.5s'.$shortSuffix,
            null,
            [Substrate::STEEL_CARBON],
        );
        $this->em->persist($treatment);
        $this->em->flush();

        $this->treatmentId = $treatment->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $t = $em->find(SurfaceTreatment::class, Uuid::fromString($this->treatmentId));
            if (null !== $t) {
                $em->remove($t);
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

    public function test_returns_items_by_q(): void
    {
        $this->client->request('GET', '/cabinet/coating/surface-treatment/suggest', [
            'q' => 'Пескоструйная очистка suggest',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        // ResponseListener оборачивает JsonResponse в {result, status, data, message}
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        self::assertNotEmpty($items);

        $ids = array_column($items, 'id');
        self::assertContains($this->treatmentId, $ids);
    }

    public function test_empty_q_returns_empty_items(): void
    {
        $this->client->request('GET', '/cabinet/coating/surface-treatment/suggest', [
            'q' => '',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $items = $response['data']['items'] ?? $response['items'] ?? null;
        self::assertSame([], $items);
    }

    public function test_items_have_expected_keys(): void
    {
        $this->client->request('GET', '/cabinet/coating/surface-treatment/suggest', [
            'q' => 'Пескоструйная очистка suggest',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        self::assertNotEmpty($items);
        $first = $items[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
        self::assertArrayHasKey('description', $first);
    }
}
