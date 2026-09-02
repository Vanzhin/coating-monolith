<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use App\Tests\Support\CsrfTestHelper;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class UpdateActionTest extends WebTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $systemId;
    /** @var list<string> */
    private array $createdTagIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_cs_update_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        // Мутация систем покрытий — только ROLE_ADMIN.
        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $system = new CoatingSystem(
            Uuid::v7(),
            'Исходная система_'.$suffix,
            'Исходное описание',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $this->em->persist($system);
        $this->em->flush();

        $this->systemId = $system->getId();
        $this->client->loginUser($user);
        CsrfTestHelper::enable($this->client);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $system = $em->find(CoatingSystem::class, Uuid::fromString($this->systemId));
            if (null !== $system) {
                $em->remove($system);
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

    public function test_get_shows_prefilled_form(): void
    {
        $this->client->request('GET', sprintf('/cabinet/coating/coating-system/%s/update', $this->systemId));

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Обновление системы покрытий', $content);
        self::assertStringContainsString('Исходная система_', $content);
        self::assertStringContainsString('name="surfaceTreatmentId"', $content);
        // Проверяем, что treatment отрисован как select, а не input
        self::assertStringContainsString('<select', $content);
        self::assertStringContainsString('Выберите подготовку поверхности', $content);
        // Проверяем, что созданный treatmentId присутствует в options и выбран
        self::assertStringContainsString((string) $this->treatmentId, $content);
    }

    public function test_post_valid_data_updates_and_redirects(): void
    {
        $this->client->request('POST', sprintf('/cabinet/coating/coating-system/%s/update', $this->systemId), [
            'title' => 'Обновлённая система',
            'description' => 'Новое описание',
            'substrate' => 'concrete',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
        ]);

        self::assertResponseRedirects('/cabinet/coating/coating-system/list');

        // Проверяем, что данные обновлены
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $system = $em->find(CoatingSystem::class, Uuid::fromString($this->systemId));
        self::assertNotNull($system);
        self::assertSame('Обновлённая система', $system->getTitle());
        self::assertSame(Substrate::CONCRETE, $system->getSubstrate());
    }

    public function test_post_validation_error_preserves_form_fields(): void
    {
        $this->client->request('POST', sprintf('/cabinet/coating/coating-system/%s/update', $this->systemId), [
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

        // Поля восстановлены из POST-данных
        self::assertStringContainsString('Сохранённое описание', $content);
        self::assertStringContainsString('value="steel_carbon"', $content);
        // surfaceTreatmentId — присутствует как option selected
        self::assertStringContainsString((string) $this->treatmentId, $content);
        self::assertStringContainsString('selected', $content);
    }

    public function test_post_with_invalid_layer_coating_id_shows_validation_error_not_500(): void
    {
        $this->client->request('POST', sprintf('/cabinet/coating/coating-system/%s/update', $this->systemId), [
            'title' => 'Система с кривым слоем',
            'description' => '',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'surfaceTreatmentId' => (string) $this->treatmentId,
            'layers' => [
                ['coatingId' => 'not-a-uuid', 'dft' => '100'],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();

        self::assertStringContainsString('alert-danger', $content);
        self::assertStringNotContainsString('Could not convert', $content);
    }

    public function test_post_with_tag_ids_persists_tags_in_db(): void
    {
        $container = $this->client->getContainer();
        $tagSpec = $container->get(TagSpecification::class);
        $tag = new Tag('нефтяной-тест-'.uniqid('', true), $tagSpec);
        $container->get(TagRepositoryInterface::class)->add($tag);
        $this->createdTagIds[] = $tag->getId();

        $this->client->request('POST', sprintf('/cabinet/coating/coating-system/%s/update', $this->systemId), [
            'title' => 'Система с тегом',
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
            'SELECT tag_id FROM coating_system_tag WHERE coating_system_id = ?',
            [$this->systemId],
        );

        self::assertCount(1, $joinRows, 'Тег должен быть привязан к обновлённой системе покрытий.');
        self::assertSame($tag->getId(), $joinRows[0]['tag_id']);
    }
}
