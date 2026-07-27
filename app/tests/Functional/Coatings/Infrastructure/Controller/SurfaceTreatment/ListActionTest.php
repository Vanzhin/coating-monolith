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

final class ListActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $treatmentId;
    private string $treatmentIdConcrete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_st_list_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);

        $treatment = new SurfaceTreatment(
            Uuid::v7(),
            'Список очистка сталь '.$suffix,
            'Sa2.5',
            null,
            [Substrate::STEEL_CARBON],
        );
        $this->em->persist($treatment);

        $treatmentConcrete = new SurfaceTreatment(
            Uuid::v7(),
            'Список очистка бетон '.$suffix,
            'CSP3',
            null,
            [Substrate::CONCRETE],
        );
        $this->em->persist($treatmentConcrete);

        $this->em->flush();

        $this->treatmentId = $treatment->getId();
        $this->treatmentIdConcrete = $treatmentConcrete->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ([$this->treatmentId, $this->treatmentIdConcrete] as $id) {
                $t = $em->find(SurfaceTreatment::class, Uuid::fromString($id));
                if (null !== $t) {
                    $em->remove($t);
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

    public function test_get_shows_treatments(): void
    {
        $this->client->request('GET', '/cabinet/coating/surface-treatment/list');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Подготовка поверхности', $content);
        self::assertStringContainsString('Список очистка сталь', $content);
    }

    public function test_get_with_substrate_filter(): void
    {
        $this->client->request('GET', '/cabinet/coating/surface-treatment/list', [
            'substrate' => Substrate::STEEL_CARBON->value,
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Список очистка сталь', $content);
        self::assertStringNotContainsString('Список очистка бетон', $content);
    }

    public function test_get_with_q_filter(): void
    {
        $this->client->request('GET', '/cabinet/coating/surface-treatment/list', [
            'q' => 'бетон',
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Список очистка бетон', $content);
        self::assertStringNotContainsString('Список очистка сталь', $content);
    }
}
