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

final class AddActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_st_add_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        // Мутация подготовки поверхности — только ROLE_ADMIN.
        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdIds as $id) {
                $treatment = $em->find(SurfaceTreatment::class, Uuid::fromString($id));
                if (null !== $treatment) {
                    $em->remove($treatment);
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

    public function test_get_shows_empty_form(): void
    {
        $this->client->request('GET', '/cabinet/coating/surface-treatment/add');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Добавление подготовки поверхности', $content);
        self::assertStringContainsString('name="description"', $content);
        self::assertStringContainsString('name="substrateScope[]"', $content);
    }

    public function test_post_valid_data_creates_treatment_and_redirects(): void
    {
        $suffix = uniqid('', true);
        $this->client->request('POST', '/cabinet/coating/surface-treatment/add', [
            'description' => 'Пескоструйная очистка '.$suffix,
            'code' => 'Sa2',
            'standardCode' => 'ISO 8501-1',
            'substrateScope' => [Substrate::STEEL_CARBON->value],
        ]);

        self::assertResponseRedirects('/cabinet/coating/surface-treatment/list');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $treatments = $em->getRepository(SurfaceTreatment::class)->findAll();
        foreach ($treatments as $t) {
            if (str_contains($t->getDescription(), $suffix)) {
                $this->createdIds[] = $t->getId();
            }
        }

        self::assertNotEmpty($this->createdIds, 'Запись подготовки поверхности должна быть создана в БД.');
    }

    public function test_post_empty_description_returns_error(): void
    {
        $this->client->request('POST', '/cabinet/coating/surface-treatment/add', [
            'description' => '',
            'substrateScope' => [Substrate::STEEL_CARBON->value],
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('form-alert', $content);
    }

    public function test_post_validation_error_shows_human_readable_message(): void
    {
        $this->client->request('POST', '/cabinet/coating/surface-treatment/add', [
            'description' => '',
            'substrateScope' => [Substrate::STEEL_CARBON->value],
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('form-alert', $content);
        self::assertStringContainsString('Описание:', $content);
        self::assertStringNotContainsString('[description]', $content);
    }
}
