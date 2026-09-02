<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use App\Tests\Support\CsrfTestHelper;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class RemoveActionTest extends WebTestCase
{
    use SurfaceTreatmentFixtureTrait;

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
        $this->userEmail = 'test_cs_remove_'.$suffix.'@example.com';

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
            'Удалить-система-'.$suffix,
            'Система для удаления',
            Substrate::ALUMINUM,
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
            // Если удаление не выполнено в тесте (например, тест упал) — чистим вручную
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
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_post_removes_system_and_redirects(): void
    {
        $this->client->request('POST', sprintf('/cabinet/coating/coating-system/%s/remove', $this->systemId));

        self::assertResponseRedirects('/cabinet/coating/coating-system/list');

        // Проверяем, что система удалена
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $system = $em->find(CoatingSystem::class, Uuid::fromString($this->systemId));
        self::assertNull($system, 'Система должна быть удалена из БД.');
    }
}
