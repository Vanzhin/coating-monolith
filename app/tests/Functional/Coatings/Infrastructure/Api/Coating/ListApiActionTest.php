<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Api\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
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

final class ListApiActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $userPassword;
    private string $suffix;
    private string $coatingId;
    private string $manufacturerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));
        $this->userEmail = 'test_coating_api_list_'.$this->suffix.'@example.com';
        $this->userPassword = 'test_api_password_'.$this->suffix;

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword($this->userPassword, $hasher);
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);
        $this->em->persist($user);

        $manufacturer = new Manufacturer(
            'МинимальТест'.$this->suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = $manufacturer->getId();

        $coating = new Coating(
            UuidService::generateUuid(),
            'ЭпоксидТест'.$this->suffix,
            'Описание тестового покрытия.',
            50,
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
        $container->get(CoatingRepositoryInterface::class)->add($coating);
        $this->em->flush();
        $this->coatingId = $coating->getId();
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
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

    private function loginAndGetToken(): string
    {
        $this->client->request(
            'POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $this->userEmail, 'password' => $this->userPassword]),
        );

        $response = json_decode($this->client->getResponse()->getContent(), true);

        return $response['data']['token'] ?? '';
    }

    public function test_get_without_filter_returns_200_with_items(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request('GET', '/api/coatings');

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $body['result']);
        self::assertArrayHasKey('items', $body['data']);
        self::assertIsArray($body['data']['items']);

        $ids = array_column($body['data']['items'], 'id');
        self::assertContains($this->coatingId, $ids);
    }

    public function test_get_with_q_filter_returns_matching_items(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        // Первые 5 символов — достаточно длинный и уникальный префикс кириллицы
        $q = mb_substr('ЭпоксидТест'.$this->suffix, 0, 6);
        $this->client->request('GET', '/api/coatings', ['q' => $q]);

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $body['result']);

        $items = $body['data']['items'];
        self::assertNotEmpty($items);

        $ids = array_column($items, 'id');
        self::assertContains($this->coatingId, $ids);
    }

    public function test_response_items_contain_required_fields(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request('GET', '/api/coatings');

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $items = $body['data']['items'];

        $found = null;
        foreach ($items as $item) {
            if ($item['id'] === $this->coatingId) {
                $found = $item;
                break;
            }
        }

        self::assertNotNull($found, 'Тестовое покрытие должно быть в списке.');
        self::assertArrayHasKey('id', $found);
        self::assertArrayHasKey('title', $found);
        self::assertArrayHasKey('base', $found);
        self::assertArrayHasKey('dftMin', $found);
        self::assertArrayHasKey('dftMax', $found);
        self::assertSame(80, $found['dftMin']);
        self::assertSame(150, $found['dftMax']);
        self::assertSame('EP', $found['base']);
    }

    public function test_requires_authentication(): void
    {
        $this->client->request('GET', '/api/coatings');
        self::assertResponseStatusCodeSame(401);
    }
}
