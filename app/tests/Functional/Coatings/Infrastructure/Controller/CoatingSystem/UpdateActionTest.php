<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class UpdateActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $systemId;

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

        $this->em->persist($user);

        $chainValidator = new CoatingSystemChainValidator();
        $system = new CoatingSystem(
            Uuid::v7(),
            'Исходная система_'.$suffix,
            'Исходное описание',
            Substrate::STEEL_CARBON,
            new SurfacePreparation('Sa 2', 'Пескоструйная', 'ISO 8501-1'),
            $chainValidator,
        );
        $this->em->persist($system);
        $this->em->flush();

        $this->systemId = $system->getId();
        $this->client->loginUser($user);
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
    }

    public function test_post_valid_data_updates_and_redirects(): void
    {
        $this->client->request('POST', sprintf('/cabinet/coating/coating-system/%s/update', $this->systemId), [
            'title' => 'Обновлённая система',
            'description' => 'Новое описание',
            'substrate' => 'concrete',
            'surfacePreparation' => [
                'grade' => 'CSP 3',
                'description' => 'Механическая подготовка',
                'standard' => 'ICRI 310.2',
            ],
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
}
