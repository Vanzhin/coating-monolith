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

final class ViewActionTest extends WebTestCase
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
        $this->userEmail = 'test_cs_view_'.$suffix.'@example.com';

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
            'Просмотр-система-'.$suffix,
            'Описание для теста просмотра',
            Substrate::STEEL_GALVANIZED,
            new SurfacePreparation('St 2', 'Ручная зачистка', null),
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

    public function test_get_shows_system_details(): void
    {
        $this->client->request('GET', sprintf('/cabinet/coating/coating-system/%s', $this->systemId));

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Просмотр-система-', $content);
        self::assertStringContainsString('Слои системы', $content);
        self::assertStringContainsString('Соответствие стандартам', $content);
    }

    public function test_get_unknown_id_redirects_to_list(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000001';
        $this->client->request('GET', sprintf('/cabinet/coating/coating-system/%s', $fakeId));

        self::assertResponseRedirects('/cabinet/coating/coating-system/list');
    }
}
