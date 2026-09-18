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

final class CatalogActionTest extends WebTestCase
{
    private const URL = '/cabinet/coating/coating/catalog';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
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
        $this->userEmail = 'test_catalog_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User(new Email($this->userEmail));
        $this->user->setPassword('test_password', $hasher);
        $this->forceProp($this->user, 'isActive', true);
        $this->forceProp($this->user, 'roles', ['ROLE_ADMIN']);
        $this->em->persist($this->user);

        $manufacturer = new Manufacturer('CatalogMfr_'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($manufacturer);
        $this->manufacturerId = $manufacturer->getId();

        $coating = new Coating(
            UuidService::generateUuid(),
            'CatalogCoating '.$suffix,
            'desc',
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
            $container->get(CoatingSpecification::class),
        );
        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingId = $coating->getId();
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

    public function test_returns_full_catalog_with_version(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $payload = $this->payload();

        self::assertArrayHasKey('items', $payload);
        self::assertArrayHasKey('version', $payload);
        self::assertNotSame('', $payload['version']);

        $ids = array_column($payload['items'], 'id');
        self::assertContains($this->coatingId, $ids);
        // shape строки — как у suggest (общий CoatingSuggestNormalizer).
        self::assertArrayHasKey('mixingRatio', $payload['items'][0]);
        self::assertArrayHasKey('volumeSolid', $payload['items'][0]);
    }

    public function test_returns_304_on_matching_if_none_match(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', self::URL);
        $version = $this->payload()['version'];

        $this->client->request('GET', self::URL, server: ['HTTP_IF_NONE_MATCH' => $version]);
        self::assertSame(304, $this->client->getResponse()->getStatusCode());
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $this->client->request('GET', self::URL);
        self::assertResponseStatusCodeSame(302);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $response['data'] ?? $response;
    }

    private function forceProp(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
