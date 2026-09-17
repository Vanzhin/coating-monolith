<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Coating;

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

/**
 * Smoke-тест админ-журнала: маршрут резолвится в AuditJournalAction (не в {id}-роут),
 * отдаёт изменения покрытий, фильтр по актору вырезает записи чужого актора.
 */
final class AuditJournalActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $coatingId;
    private string $manufacturerId;
    private string $updatedTitle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_audit_journal_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);
        $this->em->flush();

        // Логинимся ДО создания/правки покрытия, чтобы actorId аудит-записей был
        // ulid этого юзера, а не системный принципал (AuthOnFlushListener берёт актора
        // из Security на момент flush).
        $this->client->loginUser($user);

        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = $container->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer('AuditJournalMfr_'.$suffix, $manufacturerSpec);
        $this->em->persist($manufacturer);

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = $container->get(CoatingSpecification::class);

        $coating = new Coating(
            UuidService::generateUuid(),
            'AuditJournalCoating_'.$suffix,
            'Description audit journal',
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

        // Мутация — источник записи с action=Updated, которую ищет журнал.
        $this->updatedTitle = 'AuditJournalUpdated_'.$suffix;
        $coating->setTitle($this->updatedTitle);
        $this->em->flush();

        $this->coatingId = $coating->getId();
        $this->manufacturerId = $manufacturer->getId();
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

    public function test_journal_resolves_to_this_action_and_shows_the_change(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/audit-journal');

        self::assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Журнал изменений', $html);
        self::assertStringContainsString($this->updatedTitle, $html);
        self::assertStringContainsString($this->coatingId, $html);
    }

    public function test_actor_filter_hides_entries_of_other_actors(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/audit-journal', ['actor' => 'nonmatching-actor-id']);

        self::assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($this->updatedTitle, $html);
    }
}
