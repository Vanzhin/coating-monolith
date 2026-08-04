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

final class ByIdsActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $coatingId;
    private string $coatingTitle;
    private string $manufacturerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_coating_byids_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        // Обычный пользователь без ROLE_ADMIN — эндпоинт должен быть доступен всем в кабинете.
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);

        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = $container->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer('ByIdsMfr_'.$suffix, $manufacturerSpec);
        $this->em->persist($manufacturer);

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = $container->get(CoatingSpecification::class);

        $this->coatingTitle = 'ByIdsCoating '.$suffix;
        $coating = new Coating(
            UuidService::generateUuid(),
            $this->coatingTitle,
            'Description by-ids',
            60,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
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

    public function test_returns_id_and_title_by_ids(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/by-ids', [
            'ids' => [$this->coatingId],
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame($this->coatingId, $items[0]['id']);
        self::assertSame($this->coatingTitle, $items[0]['title']);
        self::assertSame(['id', 'title'], array_keys($items[0]));
    }

    public function test_empty_ids_returns_empty_items(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/by-ids');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items());
    }

    public function test_invalid_id_is_ignored(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/by-ids', [
            'ids' => ['not-a-uuid', $this->coatingId],
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame($this->coatingId, $items[0]['id']);
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    private function items(): array
    {
        $response = json_decode($this->client->getResponse()->getContent(), true);

        // ResponseListener оборачивает JsonResponse в {result, status, data, message}.
        return $response['data']['items'] ?? $response['items'] ?? [];
    }
}
