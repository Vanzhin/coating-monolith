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
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class SuggestActionTest extends WebTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $systemId;
    private string $coatingId;
    private string $manufacturerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_cs_suggest_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setAccessible(true);
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);

        $treatment = $this->createAndPersistTreatment($this->em, substr($suffix, -6));

        $mfr = new Manufacturer('SuggestCSMfr_'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($mfr);
        $this->em->flush();
        $this->manufacturerId = $mfr->getId();

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Coating-SuggestCS-'.$suffix,
            'Test coating.',
            5,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 80, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 120))),
            null,
            1.0,
            null,
            $mfr,
            $container->get(CoatingSpecification::class),
        );
        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingId = (string) $coatingId;

        $repo = $container->get(CoatingSystemRepositoryInterface::class);
        $searchCache = $container->get(CoatingSystemSearchCacheRepository::class);

        $systemUuid = Uuid::v7();
        $system = new CoatingSystem($systemUuid, 'SuggestCSTitle '.$suffix, 'Описание', Substrate::STEEL_CARBON, $treatment);
        $system->appendLayer($coating, 80);
        $repo->save($system);
        $this->em->clear();

        $loaded = $repo->findById($systemUuid);
        \assert(null !== $loaded);
        $searchCache->upsert($loaded);

        $this->systemId = $loaded->getId();

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

            $coating = $em->find(Coating::class, Uuid::fromString($this->coatingId));
            if (null !== $coating) {
                $em->remove($coating);
            }

            $manufacturer = $em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
            if (null !== $manufacturer) {
                $em->remove($manufacturer);
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

    public function test_returns_items_by_q(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/suggest', [
            'q' => 'SuggestCSTitle',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        // ResponseListener оборачивает JsonResponse в {result, status, data, message}
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        self::assertNotEmpty($items);

        $ids = array_column($items, 'id');
        self::assertContains($this->systemId, $ids);
    }

    public function test_empty_q_returns_empty_items(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/suggest', [
            'q' => '',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $items = $response['data']['items'] ?? $response['items'] ?? null;
        self::assertSame([], $items);
    }

    public function test_items_have_expected_keys(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/suggest', [
            'q' => 'SuggestCSTitle',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        self::assertNotEmpty($items);
        $first = $items[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
    }
}
