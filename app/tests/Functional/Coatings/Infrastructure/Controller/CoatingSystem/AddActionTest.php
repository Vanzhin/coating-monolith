<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
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
    /** @var list<string> */
    private array $createdTagIds = [];

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

            foreach ($this->createdTagIds as $tagId) {
                $tag = $em->find(Tag::class, $tagId);
                if (null !== $tag) {
                    $em->remove($tag);
                }
            }
            if ([] !== $this->createdTagIds) {
                $em->flush();
            }
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
        // Endpoint async-typeahead указывает на cabinet-роут suggest
        self::assertStringContainsString('data-async-typeahead-endpoint-value="/cabinet/coating/surface-treatment/suggest"', $content);
    }

    public function test_post_valid_data_creates_system_and_redirects(): void
    {
        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => 'Тестовая система покрытий',
            'description' => 'Описание для теста',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
        ]);

        self::assertResponseRedirects('/cabinet/coating/coating-system/list');

        // Записываем ID для tearDown
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();

        /** @var CoatingSystemRepositoryInterface $repo */
        $repo = $container->get(CoatingSystemRepositoryInterface::class);
        foreach ($repo->findAll() as $s) {
            if ('Тестовая система покрытий' === $s->getTitle()) {
                $this->createdSystemIds[] = $s->getId();
            }
        }
    }

    public function test_post_missing_title_returns_error(): void
    {
        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => '',
            'description' => 'Описание',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('alert-danger', $content);
    }

    public function test_post_validation_error_shows_human_readable_message(): void
    {
        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => '',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('alert-danger', $content);
        self::assertStringContainsString('Название:', $content);
        self::assertStringNotContainsString('[title]', $content);
    }

    public function test_post_missing_coating_in_layer_shows_human_readable_message(): void
    {
        $fakeUuid = Uuid::v7();
        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => 'Тест',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
            'layers' => [
                ['coatingId' => '', 'dft' => '80'],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('alert-danger', $content);
        self::assertStringContainsString('Слой №1: Покрытие', $content);
        self::assertStringNotContainsString('[layers][0][coatingId]', $content);
    }

    public function test_post_validation_error_preserves_form_fields(): void
    {
        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => '',
            'description' => 'Сохранённое описание',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();

        // Ошибка показана
        self::assertStringContainsString('alert-danger', $content);

        // Поля восстановлены
        self::assertStringContainsString('Сохранённое описание', $content);
        self::assertStringContainsString('value="steel_carbon"', $content);
        // surfaceTreatmentId — присутствует как option selected в async-typeahead select
        self::assertStringContainsString((string) $this->treatmentId, $content);
        self::assertStringContainsString('selected', $content);
    }

    public function test_post_domain_error_preserves_form_fields(): void
    {
        // Создаём treatment, применимый только к concrete, а в форме шлём steel_carbon
        // — domain бросит AppException про несовместимость.
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $concreteTreatmentId = Uuid::v7();
        $concreteTreatment = new \App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment(
            $concreteTreatmentId,
            'Подготовка только для бетона',
            'Test-Concrete',
            null,
            [\App\Coatings\Domain\Aggregate\CoatingSystem\Substrate::CONCRETE],
        );
        $em->persist($concreteTreatment);
        $em->flush();

        try {
            $this->client->request('POST', '/cabinet/coating/coating-system/add', [
                'title' => 'Моя система',
                'description' => 'Описание системы',
                'substrate' => 'steel_carbon',
                'environment' => 'atmospheric',
                'surfaceTreatmentId' => (string) $concreteTreatmentId,
            ]);

            self::assertResponseIsSuccessful();
            $content = $this->client->getResponse()->getContent();

            // Ошибка домена показана
            self::assertStringContainsString('alert-danger', $content);

            // Все поля восстановлены
            self::assertStringContainsString('value="Моя система"', $content);
            self::assertStringContainsString('Описание системы', $content);
            self::assertStringContainsString('value="steel_carbon"', $content);
            self::assertStringContainsString((string) $concreteTreatmentId, $content);
            self::assertStringContainsString('selected', $content);
        } finally {
            $em2 = static::getContainer()->get(EntityManagerInterface::class);
            $em2->clear();
            $t = $em2->find(\App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment::class, $concreteTreatmentId);
            if (null !== $t) {
                $em2->remove($t);
                $em2->flush();
            }
        }
    }

    public function test_post_with_tag_ids_persists_tags_in_db(): void
    {
        $container = $this->client->getContainer();
        $tagSpec = $container->get(TagSpecification::class);
        $tag = new Tag('морской-тест-'.uniqid('', true), $tagSpec);
        $container->get(TagRepositoryInterface::class)->add($tag);
        $this->createdTagIds[] = $tag->getId();

        $this->client->request('POST', '/cabinet/coating/coating-system/add', [
            'title' => 'Система с тегами '.uniqid('', true),
            'description' => '',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
            'tagIds' => [$tag->getId()],
        ]);

        self::assertResponseRedirects('/cabinet/coating/coating-system/list');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $joinRows = $em->getConnection()->fetchAllAssociative(
            'SELECT cs.id FROM coating_system cs
             INNER JOIN coating_system_tag cst ON cst.coating_system_id = cs.id
             WHERE cst.tag_id = ?
             ORDER BY cs.created_at DESC
             LIMIT 1',
            [$tag->getId()],
        );

        self::assertCount(1, $joinRows, 'Тег должен быть привязан к созданной системе покрытий.');
        $this->createdSystemIds[] = $joinRows[0]['id'];
    }
}
