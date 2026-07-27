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

final class UpdateActionTest extends WebTestCase
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
        $this->userEmail = 'test_st_update_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);

        $treatment = new SurfaceTreatment(
            Uuid::v7(),
            'Исходное описание очистки '.$suffix,
            'Sa2',
            'ISO 8501-1',
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
            $treatment = $em->find(SurfaceTreatment::class, Uuid::fromString($this->treatmentId));
            if (null !== $treatment) {
                $em->remove($treatment);
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
        $this->client->request('GET', sprintf('/cabinet/coating/surface-treatment/%s/update', $this->treatmentId));

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Редактирование подготовки поверхности', $content);
        self::assertStringContainsString('Исходное описание очистки', $content);
    }

    public function test_post_valid_data_updates_and_redirects(): void
    {
        $this->client->request('POST', sprintf('/cabinet/coating/surface-treatment/%s/update', $this->treatmentId), [
            'description' => 'Обновлённое описание очистки',
            'code' => 'P3',
            'standardCode' => 'ISO 11124',
            'substrateScope' => [Substrate::CONCRETE->value],
        ]);

        self::assertResponseRedirects('/cabinet/coating/surface-treatment/list');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $treatment = $em->find(SurfaceTreatment::class, Uuid::fromString($this->treatmentId));
        self::assertNotNull($treatment);
        self::assertSame('Обновлённое описание очистки', $treatment->getDescription());
        self::assertSame([Substrate::CONCRETE], $treatment->getSubstrateScope());
    }

    public function test_get_nonexistent_id_redirects(): void
    {
        $fakeId = (string) Uuid::v7();
        $this->client->request('GET', sprintf('/cabinet/coating/surface-treatment/%s/update', $fakeId));

        self::assertResponseRedirects('/cabinet/coating/surface-treatment/list');
    }
}
