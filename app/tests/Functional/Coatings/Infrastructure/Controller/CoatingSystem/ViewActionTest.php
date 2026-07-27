<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
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

    public function test_view_shows_compliance_badge_when_system_matches_standard(): void
    {
        $container = $this->client->getContainer();
        $suffix = bin2hex(random_bytes(3));

        $manufacturer = new Manufacturer(
            'Мфр-ViewBadge-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'EP-Грунт-ViewBadge-'.$suffix,
            'Тестовое покрытие для compliance.',
            60,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 80, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            null,
            1.0,
            null,
            $manufacturer,
            $container->get(CoatingSpecification::class),
        );
        $this->em->persist($coating);

        $systemId = Uuid::v7();
        $chainValidator = new CoatingSystemChainValidator();
        $system = new CoatingSystem(
            $systemId,
            'Просмотр-Badge-'.$suffix,
            '',
            Substrate::STEEL_GALVANIZED,
            new SurfacePreparation('Sa 2½', 'Дробеструйная', 'ISO 8501-1'),
            $chainValidator,
        );
        $system->appendLayer($coating, 80);
        $this->em->persist($system);
        $this->em->flush();

        $this->client->request('GET', sprintf('/cabinet/coating/coating-system/%s', $systemId->toRfc4122()));

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('ISO 12944', $content);
        self::assertStringContainsString('C2', $content);

        // Cleanup — remove the extra system+coating+manufacturer created in this test
        $this->em->clear();
        $s = $this->em->find(CoatingSystem::class, $systemId);
        if (null !== $s) {
            $this->em->remove($s);
        }
        $c = $this->em->find(Coating::class, $coatingId);
        if (null !== $c) {
            $this->em->remove($c);
        }
        $m = $this->em->find(Manufacturer::class, Uuid::fromString($manufacturer->getId()));
        if (null !== $m) {
            $this->em->remove($m);
        }
        $this->em->flush();
    }
}
