<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\Coating;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
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

/**
 * Рендер страницы «Химстойкость» (обе ветки: пустой ввод и выдача по веществу).
 */
final class BySubstanceActionTest extends WebTestCase
{
    private const URL = '/cabinet/coating/coating/by-substance';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $coatingId;
    private string $manufacturerId;
    private string $substanceId;
    private string $substanceName;
    private string $assessmentId;
    private string $coatingTitle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_bysubstance_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $activeRef = new \ReflectionProperty($user, 'isActive');
        $activeRef->setAccessible(true);
        $activeRef->setValue($user, true);

        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setAccessible(true);
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);

        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = $container->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer('BySubMfr_'.$suffix, $manufacturerSpec);
        $this->em->persist($manufacturer);

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = $container->get(CoatingSpecification::class);

        $this->coatingTitle = 'BySubCoating '.$suffix;
        $coating = new Coating(
            UuidService::generateUuid(),
            $this->coatingTitle,
            'Описание by-substance',
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

        $this->substanceName = 'Вещество-render-'.$suffix;
        $substanceId = Uuid::v4();
        $substance = new Substance(
            $substanceId,
            $this->substanceName,
            null,
            new StringCollection(),
            $container->get(SubstanceSpecification::class),
        );
        $container->get(\App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository::class)->add($substance);

        $assessmentId = Uuid::v4();
        $assessment = new Assessment(
            $assessmentId,
            Uuid::fromString($coating->getId()),
            $substanceId,
            Grade::R,
            AssessmentTemperature::fromInt(45),
            new StringCollection(),
            $container->get(AssessmentSpecification::class),
        );
        $container->get(\App\ChemicalResistance\Infrastructure\Repository\AssessmentRepository::class)->add($assessment);

        $this->em->flush();

        $this->coatingId = $coating->getId();
        $this->manufacturerId = $manufacturer->getId();
        $this->substanceId = $substanceId->toRfc4122();
        $this->assessmentId = $assessmentId->toRfc4122();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $a = $em->find(Assessment::class, Uuid::fromString($this->assessmentId));
            if (null !== $a) {
                $em->remove($a);
            }
            $em->flush();

            $s = $em->find(Substance::class, Uuid::fromString($this->substanceId));
            if (null !== $s) {
                $em->remove($s);
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
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_empty_state_renders(): void
    {
        $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Химстойкость');
        self::assertSelectorTextContains('body', 'Поиск по химстойкости');

        // Typeahead-проводка: контроллер и endpoint должны быть на обёртке поля.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-controller="substance-multiselect"', $html);
        self::assertStringContainsString('data-substance-multiselect-endpoint-value="/cabinet/chemical-resistance/substance/autocomplete"', $html);
        self::assertStringContainsString('data-substance-multiselect-target="input"', $html);
        self::assertStringContainsString('data-substance-multiselect-target="results"', $html);
    }

    public function test_results_render_with_chip_and_verdict(): void
    {
        $this->client->request('GET', self::URL, ['substanceIds' => [$this->substanceId]]);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString($this->coatingTitle, $html, 'Стойкое покрытие в выдаче.');
        self::assertStringContainsString('Стойкое', $html, 'Вердикт-пилюля R = Стойкое.');
        self::assertStringContainsString($this->substanceName, $html, 'Чип выбранного вещества.');
    }
}
