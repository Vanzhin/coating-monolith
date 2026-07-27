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

final class RemoveActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminEmail;
    private string $userEmail;
    private string $treatmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->adminEmail = 'test_st_remove_admin_'.$suffix.'@example.com';
        $this->userEmail = 'test_st_remove_user_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = new User(new Email($this->adminEmail));
        $admin->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($admin, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($admin, true);

        $roleRef = new \ReflectionProperty($admin, 'roles');
        $roleRef->setAccessible(true);
        $roleRef->setValue($admin, ['ROLE_ADMIN']);

        $this->em->persist($admin);

        $regularUser = new User(new Email($this->userEmail));
        $regularUser->setPassword('test_password', $hasher);

        $ref2 = new \ReflectionProperty($regularUser, 'isActive');
        $ref2->setAccessible(true);
        $ref2->setValue($regularUser, true);

        $this->em->persist($regularUser);

        $treatment = new SurfaceTreatment(
            Uuid::v7(),
            'Удалить очистка '.$suffix,
            null,
            null,
            [Substrate::ALUMINUM],
        );
        $this->em->persist($treatment);
        $this->em->flush();

        $this->treatmentId = $treatment->getId();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $treatment = $em->find(SurfaceTreatment::class, Uuid::fromString($this->treatmentId));
            if (null !== $treatment) {
                $em->remove($treatment);
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

    public function test_post_admin_removes_treatment_and_redirects(): void
    {
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email.value' => $this->adminEmail]);
        $this->client->loginUser($admin);

        $this->client->request('POST', sprintf('/cabinet/coating/surface-treatment/%s/remove', $this->treatmentId));

        self::assertResponseRedirects('/cabinet/coating/surface-treatment/list');

        $em2 = static::getContainer()->get(EntityManagerInterface::class);
        $em2->clear();
        $treatment = $em2->find(SurfaceTreatment::class, Uuid::fromString($this->treatmentId));
        self::assertNull($treatment, 'Запись должна быть удалена из БД.');
    }

    public function test_post_non_admin_returns_403(): void
    {
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $regularUser = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
        $this->client->loginUser($regularUser);

        $this->client->request('POST', sprintf('/cabinet/coating/surface-treatment/%s/remove', $this->treatmentId));

        self::assertResponseStatusCodeSame(403);
    }
}
