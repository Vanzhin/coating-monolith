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

final class SearchByComplianceActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $matchingSystemTitle;

    /** @var list<Uuid> */
    private array $systemIds = [];
    /** @var list<Uuid> */
    private array $coatingIds = [];
    /** @var list<Uuid> */
    private array $manufacturerIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $this->userEmail = 'test_cs_compliance_'.$suffix.'@example.com';
        $this->matchingSystemTitle = 'Система-C3-HIGH-ctrl-'.$suffix;

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);
        $this->em->persist($user);

        $manufacturer = new Manufacturer(
            'Мфр-Ctrl-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerIds[] = Uuid::fromString($manufacturer->getId());

        $primerId = UuidService::generateUuid();
        $primer = new Coating(
            $primerId,
            'EP-Грунт-Zn-ctrl-'.$suffix,
            'Грунт для теста контроллера.',
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
            50,
            true,
        );
        $this->em->persist($primer);
        $this->coatingIds[] = $primerId;

        $topcoatId = UuidService::generateUuid();
        $topcoat = new Coating(
            $topcoatId,
            'EP-Финиш-ctrl-'.$suffix,
            'Финиш для теста контроллера.',
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
        $this->em->persist($topcoat);
        $this->coatingIds[] = $topcoatId;

        $this->em->flush();

        $chainValidator = new CoatingSystemChainValidator();

        $matchId = Uuid::v7();
        $matchingSystem = new CoatingSystem(
            $matchId,
            $this->matchingSystemTitle,
            'Система ISO 12944 C3 HIGH для теста контроллера.',
            Substrate::STEEL_CARBON,
            new SurfacePreparation('Sa 2½', 'Дробеструйная очистка', 'ISO 8501-1'),
            $chainValidator,
        );
        $matchingSystem->appendLayer($primer, 80);
        $matchingSystem->appendLayer($topcoat, 80);
        $this->em->persist($matchingSystem);
        $this->systemIds[] = $matchId;

        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->systemIds as $id) {
                $s = $em->find(CoatingSystem::class, $id);
                if (null !== $s) {
                    $em->remove($s);
                }
            }
            foreach ($this->coatingIds as $id) {
                $c = $em->find(Coating::class, $id);
                if (null !== $c) {
                    $em->remove($c);
                }
            }
            foreach ($this->manufacturerIds as $id) {
                $m = $em->find(Manufacturer::class, $id);
                if (null !== $m) {
                    $em->remove($m);
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

    public function test_get_without_params_shows_form_without_results(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/search-by-compliance');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Поиск по соответствию', $content);
        self::assertStringNotContainsString('<tbody>', $content);
    }

    public function test_get_with_valid_params_and_matches_shows_results(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/search-by-compliance', [
            'standard' => 'ISO_12944',
            'category' => 'C3',
            'durability' => 'HIGH',
            'substrate' => 'steel_carbon',
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->matchingSystemTitle, $content);
    }

    public function test_get_with_valid_params_no_matches_shows_empty_table(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/search-by-compliance', [
            'standard' => 'ISO_12944',
            'category' => 'CX',
            'durability' => 'VERY_HIGH',
            'substrate' => 'aluminum',
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Нет систем', $content);
    }

    public function test_get_with_invalid_standard_shows_error(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/search-by-compliance', [
            'standard' => 'INVALID_STANDARD',
            'category' => 'C3',
            'durability' => 'HIGH',
        ]);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Неверный стандарт', $content);
    }
}
