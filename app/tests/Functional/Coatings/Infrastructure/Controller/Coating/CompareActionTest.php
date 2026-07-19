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

final class CompareActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $manufacturerId;
    private string $userEmail;
    /** @var list<string> */
    private array $createdCoatingIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_compare_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);

        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = $container->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer(
            'TestManufacturer_'.$suffix,
            $manufacturerSpec,
        );
        $this->em->persist($manufacturer);
        $this->em->flush();

        $this->manufacturerId = $manufacturer->getId();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdCoatingIds as $id) {
                $coating = $em->find(Coating::class, Uuid::fromString($id));
                if (null !== $coating) {
                    $em->remove($coating);
                }
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

    private function createCoating(string $title, int $volumeSolid = 60): string
    {
        $container = $this->client->getContainer();

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = $container->get(CoatingSpecification::class);
        $manufacturer = $this->em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));

        $touchSeries = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $cureSeries = new DryingTimeSeries(new TimeAtTemperature(20, 1440));
        $rootDefault = new DryingTimeSeries(new TimeAtTemperature(20, 240));
        $minTree = new RecoatingIntervalTree($rootDefault);

        $coating = new Coating(
            UuidService::generateUuid(),
            $title,
            'Description for '.$title,
            $volumeSolid,
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

        $id = $coating->getId();
        $this->createdCoatingIds[] = $id;

        return $id;
    }

    public function test_renders_comparison_table_for_two_coatings(): void
    {
        $idA = $this->createCoating('Coating A', 50);
        $idB = $this->createCoating('Coating B', 70);

        $this->client->request('GET', sprintf('/cabinet/coating/coating/compare?ids=%s,%s', $idA, $idB));

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Сравнение покрытий (2)', $content);
        self::assertStringContainsString('Coating A', $content);
        self::assertStringContainsString('Coating B', $content);
        // Различия по volumeSolid должны быть подсвечены — независимо от порядка атрибутов в <tr>.
        self::assertMatchesRegularExpression(
            '/<tr(?=[^>]*class="[^"]*table-warning)(?=[^>]*data-field="volumeSolid")[^>]*>/',
            $content,
        );
    }

    public function test_redirects_when_fewer_than_two_ids(): void
    {
        $idA = $this->createCoating('Solo Coating');

        $this->client->request('GET', '/cabinet/coating/coating/compare?ids='.$idA);

        self::assertResponseRedirects('/cabinet/coating/coating/list');
        $session = $this->client->getRequest()->getSession();
        self::assertContains(
            'Выберите минимум 2 покрытия для сравнения.',
            $session->getFlashBag()->peek('compare_error'),
        );
    }

    public function test_redirects_when_all_ids_missing(): void
    {
        $fakeA = '00000000-0000-0000-0000-000000000001';
        $fakeB = '00000000-0000-0000-0000-000000000002';

        $this->client->request('GET', sprintf('/cabinet/coating/coating/compare?ids=%s,%s', $fakeA, $fakeB));

        self::assertResponseRedirects('/cabinet/coating/coating/list');
    }
}
