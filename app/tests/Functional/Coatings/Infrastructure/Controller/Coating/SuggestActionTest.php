<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
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

final class SuggestActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $coatingId;
    private string $manufacturerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_coating_suggest_'.$suffix.'@example.com';

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

        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = $container->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer('SuggestMfr_'.$suffix, $manufacturerSpec);
        $this->em->persist($manufacturer);

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = $container->get(CoatingSpecification::class);

        $touchSeries = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $cureSeries = new DryingTimeSeries(new TimeAtTemperature(20, 1440));
        $rootDefault = new DryingTimeSeries(new TimeAtTemperature(20, 240));
        $minTree = new RecoatingIntervalTree($rootDefault);

        $coating = new Coating(
            UuidService::generateUuid(),
            'SuggestCoatingXYZ '.$suffix,
            'Description suggest',
            60,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            $touchSeries,
            $cureSeries,
            $minTree,
            null,
            1.0,
            null,
            $manufacturer,
            $coatingSpec,
        );

        $this->em->persist($coating);
        $this->em->flush();

        $this->coatingId = $coating->getId();
        $this->manufacturerId = $manufacturer->getId();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
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
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_returns_items_by_q(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/suggest', [
            'q' => 'SuggestCoatingXYZ',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        // ResponseListener оборачивает JsonResponse в {result, status, data, message}
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        self::assertNotEmpty($items);

        $ids = array_column($items, 'id');
        self::assertContains($this->coatingId, $ids);
    }

    public function test_empty_q_returns_empty_items(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/suggest', [
            'q' => '',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $items = $response['data']['items'] ?? $response['items'] ?? null;
        self::assertSame([], $items);
    }

    public function test_items_have_expected_keys(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/suggest', [
            'q' => 'SuggestCoatingXYZ',
        ]);

        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        self::assertNotEmpty($items);
        $first = $items[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
        self::assertArrayHasKey('base', $first);
        self::assertArrayHasKey('dftMin', $first);
        self::assertArrayHasKey('dftMax', $first);
    }
}
