<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\AssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
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
 * Тонкие by-substance экшены оценок: добавление покрытия к веществу, правка грейда/
 * температуры, удаление; гейт админа.
 */
final class BySubstanceAssessmentActionsTest extends WebTestCase
{
    private const CREATE_URL = '/cabinet/chemical-resistance/by-substance/assessment/create';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AssessmentRepository $assessmentRepo;

    private string $adminEmail;
    private string $userEmail;
    private User $admin;
    private User $regular;
    private string $coatingId;
    private string $manufacturerId;
    private string $substanceAId;
    private string $substanceBId;
    private string $assessmentAId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->assessmentRepo = $c->get(AssessmentRepository::class);

        $suffix = uniqid('', true);
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->adminEmail = 'test_bysub_admin_'.$suffix.'@example.com';
        $this->admin = $this->makeUser($this->adminEmail, ['ROLE_ADMIN'], $hasher);

        $this->userEmail = 'test_bysub_user_'.$suffix.'@example.com';
        $this->regular = $this->makeUser($this->userEmail, ['ROLE_USER'], $hasher);

        $manufacturer = new Manufacturer('BySubActMfr_'.$suffix, $c->get(ManufacturerSpecification::class));
        $this->em->persist($manufacturer);

        $coating = new Coating(
            UuidService::generateUuid(),
            'BySubActCoating '.$suffix,
            'Описание',
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
            $c->get(CoatingSpecification::class),
        );
        $this->em->persist($coating);

        $substanceA = Uuid::v4();
        $substanceB = Uuid::v4();
        $subRepo = $c->get(SubstanceRepository::class);
        $subRepo->add(new Substance($substanceA, 'ВеществоA-'.$suffix, null, new StringCollection(), $c->get(SubstanceSpecification::class)));
        $subRepo->add(new Substance($substanceB, 'ВеществоB-'.$suffix, null, new StringCollection(), $c->get(SubstanceSpecification::class)));

        // У A уже есть оценка (для update/delete), у B — нет (для add).
        $assessmentA = Uuid::v4();
        $this->assessmentRepo->add(new Assessment(
            $assessmentA,
            Uuid::fromString($coating->getId()),
            $substanceA,
            Grade::R,
            AssessmentTemperature::fromInt(40),
            new StringCollection(),
            $c->get(AssessmentSpecification::class),
        ));

        $this->em->flush();

        $this->coatingId = $coating->getId();
        $this->manufacturerId = $manufacturer->getId();
        $this->substanceAId = $substanceA->toRfc4122();
        $this->substanceBId = $substanceB->toRfc4122();
        $this->assessmentAId = $assessmentA->toRfc4122();

        // По умолчанию — под админом (как рабочие контроллер-тесты мутаций).
        $this->client->loginUser($this->admin);
    }

    private function makeUser(string $email, array $roles, UserPasswordHasherInterface $hasher): User
    {
        $user = new User(new Email($email));
        $user->setPassword('test_password', $hasher);

        $activeRef = new \ReflectionProperty($user, 'isActive');
        $activeRef->setAccessible(true);
        $activeRef->setValue($user, true);

        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setAccessible(true);
        $rolesRef->setValue($user, $roles);

        $this->em->persist($user);

        return $user;
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $repo = static::getContainer()->get(AssessmentRepository::class);
            foreach ($repo->findAllByCoating(Uuid::fromString($this->coatingId)) as $a) {
                $managed = $em->find(Assessment::class, Uuid::fromString($a->getId()));
                if (null !== $managed) {
                    $em->remove($managed);
                }
            }
            $em->flush();

            foreach ([$this->substanceAId, $this->substanceBId] as $sid) {
                $s = $em->find(Substance::class, Uuid::fromString($sid));
                if (null !== $s) {
                    $em->remove($s);
                }
            }

            $coating = $em->find(Coating::class, Uuid::fromString($this->coatingId));
            if (null !== $coating) {
                $em->remove($coating);
            }
            $manufacturer = $em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
            if (null !== $manufacturer) {
                $em->remove($manufacturer);
            }
            foreach ([$this->adminEmail, $this->userEmail] as $email) {
                $u = $em->getRepository(User::class)->findOneBy(['email.value' => $email]);
                if (null !== $u) {
                    $em->remove($u);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    private function createUrlWithFilter(): string
    {
        return self::CREATE_URL.'?'.http_build_query(['substanceIds' => [$this->substanceBId]]);
    }

    private function updateUrl(string $assessmentId): string
    {
        return '/cabinet/chemical-resistance/by-substance/assessment/'.$assessmentId.'/update'
            .'?'.http_build_query(['substanceIds' => [$this->substanceAId]]);
    }

    private function deleteUrl(string $assessmentId): string
    {
        return '/cabinet/chemical-resistance/by-substance/assessment/'.$assessmentId.'/delete'
            .'?'.http_build_query(['substanceIds' => [$this->substanceAId]]);
    }

    public function test_admin_adds_coating_to_substance(): void
    {
        $this->client->request('POST', $this->createUrlWithFilter(), [
            'coatingId' => $this->coatingId,
            'substanceId' => $this->substanceBId,
            'grade' => 'R',
            'maxTemperatureCelsius' => '30',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $created = $this->assessmentRepo->findByCoatingAndSubstance(
            Uuid::fromString($this->coatingId),
            Uuid::fromString($this->substanceBId),
        );
        self::assertNotNull($created, 'Оценка покрытие↔вещество B создана.');
        self::assertSame(Grade::R, $created->getGrade());
        self::assertSame(30, $created->getMaxTemperature()->celsius);
    }

    public function test_admin_updates_grade_and_temperature(): void
    {
        $this->client->request('POST', $this->updateUrl($this->assessmentAId), [
            'grade' => 'LR',
            'maxTemperatureCelsius' => '45',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->assessmentRepo->findOneById($this->assessmentAId);
        self::assertNotNull($updated);
        self::assertSame(Grade::LR, $updated->getGrade(), 'Грейд обновлён 40°C→ LR.');
        self::assertSame(45, $updated->getMaxTemperature()->celsius, 'Температура обновлена 40→45.');
    }

    public function test_admin_deletes_assessment(): void
    {
        $this->client->request('POST', $this->deleteUrl($this->assessmentAId));

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNull(
            $this->assessmentRepo->findByCoatingAndSubstance(
                Uuid::fromString($this->coatingId),
                Uuid::fromString($this->substanceAId),
            ),
            'Оценка удалена.',
        );
    }

    public function test_non_admin_cannot_add(): void
    {
        $this->client->loginUser($this->regular);

        $this->client->request('POST', $this->createUrlWithFilter(), [
            'coatingId' => $this->coatingId,
            'substanceId' => $this->substanceBId,
            'grade' => 'R',
            'maxTemperatureCelsius' => '30',
        ]);

        $this->em->clear();
        self::assertNull(
            $this->assessmentRepo->findByCoatingAndSubstance(
                Uuid::fromString($this->coatingId),
                Uuid::fromString($this->substanceBId),
            ),
            'Не-админ не может создать оценку — гейт в команде.',
        );
    }
}
