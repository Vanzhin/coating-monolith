<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AddActionTest extends WebTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    /** @var list<string> */
    private array $createdSystemIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_cs_add_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);
        $this->createAndPersistTreatment($this->em, $suffix);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdSystemIds as $id) {
                $system = $em->find(CoatingSystem::class, Uuid::fromString($id));
                if (null !== $system) {
                    $em->remove($system);
                }
            }

            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if (null !== $user) {
                $em->remove($user);
            }

            $em->flush();
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_get_shows_empty_form(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/add');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Добавление системы покрытий', $content);
        self::assertStringContainsString('name="title"', $content);
        self::assertStringContainsString('name="substrate"', $content);
        // После перехода на async-typeahead hidden input берёт name, а не select
        // — проверяем наличие поля через data-атрибут или endpoint
        self::assertStringContainsString('<select', $content);
        self::assertStringContainsString('Выберите подготовку поверхности', $content);
        // Endpoint async-typeahead указывает на /api/surface-treatments
        self::assertStringContainsString('data-async-typeahead-endpoint-value="/api/surface-treatments"', $content);
    }

    public function test_post_valid_data_creates_system_and_redirects(): void
    {
        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => 'Тестовая система покрытий',
            'description' => 'Описание для теста',
            'substrate' => 'steel_carbon',
            'surfaceTreatmentId' => (string) $this->treatmentId,
        ]);

        self::assertResponseRedirects('/cabinet/coating/coating-system/list');

        // Записываем ID для tearDown
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();

        /** @var CoatingSystemRepositoryInterface $repo */
        $repo = $container->get(CoatingSystemRepositoryInterface::class);
        $systems = $repo->list(new \App\Coatings\Domain\Repository\CoatingSystemsFilter(titleLike: 'Тестовая система покрытий'), 1, 0);
        foreach ($systems as $s) {
            $this->createdSystemIds[] = $s->getId();
        }
    }

    public function test_post_missing_title_returns_error(): void
    {
        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => '',
            'description' => 'Описание',
            'substrate' => 'steel_carbon',
            'surfaceTreatmentId' => (string) $this->treatmentId,
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('alert-danger', $content);
    }
}
