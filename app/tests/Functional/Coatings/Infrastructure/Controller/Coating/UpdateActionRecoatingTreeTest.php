<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
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

/**
 * Functional end-to-end test: POST the UpdateAction with a nested recoating-interval-tree
 * and verify that the full HTTP -> mapper -> handler -> domain -> DB -> repository pipeline
 * correctly persists and returns the branched tree.
 */
final class UpdateActionRecoatingTreeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $coatingId;
    private string $manufacturerId;
    private string $userEmail;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the client once; all test code uses $this->client
        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_recoating_tree_'.$suffix.'@example.com';

        // Create an active user for cabinet authentication
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        // makeActiveInternally() requires at least one verified channel.
        // For tests we bypass that check via reflection to force isActive = true.
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);

        // Create manufacturer
        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = $container->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer(
            'TestManufacturer_'.$suffix,
            $manufacturerSpec,
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = $manufacturer->getId();

        // Create a flat coating with a root-default min-recoating interval (240 min = 4 h)
        $rootDefault = new DryingTimeSeries(new TimeAtTemperature(20, 240));
        $minTree = new RecoatingIntervalTree($rootDefault);

        $touchSeries = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $cureSeries = new DryingTimeSeries(new TimeAtTemperature(20, 1440));

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = $container->get(CoatingSpecification::class);

        $coating = new Coating(
            UuidService::generateUuid(),
            'TestCoating_'.$suffix,
            'A test coating for functional tree test.',
            50,
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

        // Authenticate the user for the session (must be done on the same client)
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        // Fetch a fresh EM from the container — $this->em captured in setUp may be closed
        // after the kernel reboot triggered by $this->client->request(...).
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
            // Don't mask the underlying test failure — but log so a polluting failure is visible.
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_submitting_tree_with_branch_persists_and_is_accessible_via_lookup(): void
    {
        // POST with a nested tree:
        //   root default:             4 h  (240 min)
        //   atmospheric default:      3 h  (180 min)
        //   atmospheric -> ep:        2 h  (120 min)
        $this->client->request('POST', "/cabinet/coating/coating/{$this->coatingId}/edit", [
            'title' => 'Updated Coating',
            'description' => 'Updated description for tree test.',
            'volumeSolid' => 50,
            'massDensity' => 1.5,
            'base' => 'EP',
            'minDft' => 80,
            'maxDft' => 150,
            'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'pack' => 1.0,
            'dryToTouch' => [
                ['temperature_at' => 20, 'days' => 0, 'hours' => 1, 'minutes' => 0],
            ],
            'fullCure' => [
                ['temperature_at' => 20, 'days' => 1, 'hours' => 0, 'minutes' => 0],
            ],
            'manufacturer' => ['id' => $this->manufacturerId],
            'minRecoatingInterval' => [
                'default' => ['points' => [['temperature_at' => 20, 'days' => 0, 'hours' => 4, 'minutes' => 0]]],
                'branches' => [
                    'atmospheric' => [
                        'default' => ['points' => [['temperature_at' => 20, 'days' => 0, 'hours' => 3, 'minutes' => 0]]],
                        'branches' => [
                            'ep' => [
                                'default' => ['points' => [['temperature_at' => 20, 'days' => 0, 'hours' => 2, 'minutes' => 0]]],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'maxRecoatingInterval' => [
                'default' => ['points' => []],
                'branches' => [],
            ],
        ]);

        $this->assertResponseRedirects(
            null,
            null,
            'Expected a redirect after successful POST; response was: '.$this->client->getResponse()->getContent(),
        );

        // Reload the coating fresh from the DB (clear identity map first)
        $container = $this->client->getContainer();
        $repoEm = $container->get(EntityManagerInterface::class);
        $repoEm->clear();

        /** @var CoatingRepositoryInterface $repo */
        $repo = $container->get(CoatingRepositoryInterface::class);
        $coating = $repo->findOneById($this->coatingId);
        $this->assertNotNull($coating, 'Coating was not found after update.');

        // Assertion 1: atmospheric -> ep leaf must return 2 h = 120 min
        $epSeries = $coating->minRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP);
        $this->assertSame(
            120,
            $epSeries->points[0]->timeInMinutes,
            'atmospheric -> ep should be 2 h (120 min)',
        );

        // Assertion 2: immersion -> ep must fallback to the root default = 4 h = 240 min
        // The immersion branch was never submitted, so the tree falls back to the root default.
        $immersionSeries = $coating->minRecoatingFor(EnvironmentType::Immersion, CoatingBase::EP);
        $this->assertSame(
            240,
            $immersionSeries->points[0]->timeInMinutes,
            'immersion -> ep should fallback to root default (4 h = 240 min)',
        );

        // Assertion 3: atmospheric intermediate node default must be 3 h = 180 min
        $atmosphericNode = $coating->getMinRecoatingInterval()->findNode('atmospheric');
        $this->assertNotNull($atmosphericNode, 'atmospheric node must exist in the persisted tree');
        $this->assertSame(
            180,
            $atmosphericNode->default->points[0]->timeInMinutes,
            'atmospheric default should be 3 h (180 min)',
        );
    }

    public function test_submitting_max_tree_with_root_unknown_and_children_set_is_accepted(): void
    {
        // Сценарий из жалобы пользователя:
        //  - root.max: для +35°C → нет данных (unknown)
        //  - immersion.default.max: для +20°C → 12 дней (duration)
        //  - immersion.esi.default.max: для +20°C → 10 дней (duration)
        // До фикса (mapper выкидывал 0/0/0) builder падал на пустом root.default + children.
        // После — все точки с kind сохраняются как unknown/duration; домен принимает.
        $this->client->request('POST', "/cabinet/coating/coating/{$this->coatingId}/edit", [
            'title' => 'Updated Coating',
            'description' => 'Updated description.',
            'volumeSolid' => 50,
            'massDensity' => 1.5,
            'base' => 'EP',
            'minDft' => 80,
            'maxDft' => 150,
            'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'pack' => 1.0,
            'dryToTouch' => [
                ['temperature_at' => 20, 'kind' => 'duration', 'days' => 0, 'hours' => 1, 'minutes' => 0],
            ],
            'fullCure' => [
                ['temperature_at' => 20, 'kind' => 'duration', 'days' => 1, 'hours' => 0, 'minutes' => 0],
            ],
            'manufacturer' => ['id' => $this->manufacturerId],
            'minRecoatingInterval' => [
                'default' => ['points' => [
                    ['temperature_at' => 35, 'kind' => 'duration', 'days' => 0, 'hours' => 2, 'minutes' => 0],
                ]],
                'branches' => [
                    'immersion' => [
                        'default' => ['points' => [
                            ['temperature_at' => 20, 'kind' => 'duration', 'days' => 0, 'hours' => 20, 'minutes' => 0],
                        ]],
                        'branches' => [
                            'esi' => [
                                'default' => ['points' => [
                                    ['temperature_at' => 20, 'kind' => 'duration', 'days' => 5, 'hours' => 2, 'minutes' => 0],
                                ]],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'maxRecoatingInterval' => [
                'default' => ['points' => [
                    // unknown: производитель не указал верхнюю границу для общего случая
                    ['temperature_at' => 35, 'kind' => 'unknown', 'days' => 0, 'hours' => 0, 'minutes' => 0],
                ]],
                'branches' => [
                    'immersion' => [
                        'default' => ['points' => [
                            ['temperature_at' => 20, 'kind' => 'duration', 'days' => 12, 'hours' => 0, 'minutes' => 0],
                        ]],
                        'branches' => [
                            'esi' => [
                                'default' => ['points' => [
                                    ['temperature_at' => 20, 'kind' => 'duration', 'days' => 10, 'hours' => 0, 'minutes' => 0],
                                ]],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertResponseRedirects(
            null,
            null,
            'Expected redirect after successful POST; got: '.$this->client->getResponse()->getContent(),
        );

        // Reload и проверка состояния
        $container = $this->client->getContainer();
        $repoEm = $container->get(EntityManagerInterface::class);
        $repoEm->clear();

        /** @var CoatingRepositoryInterface $repo */
        $repo = $container->get(CoatingRepositoryInterface::class);
        $coating = $repo->findOneById($this->coatingId);
        $this->assertNotNull($coating);

        $maxTree = $coating->getMaxRecoatingInterval();
        $this->assertNotNull($maxTree, 'max-tree должен быть сохранён (не null), несмотря на root unknown');

        // root.default.points[0] должна быть unknown (time_in_minutes = null)
        $rootDefault = $maxTree->default;
        $this->assertCount(1, $rootDefault->points);
        $this->assertNull($rootDefault->points[0]->timeInMinutes, 'root.max@+35°C должно быть null (unknown)');

        // immersion.default.points[0] должна быть duration 12 дней = 17280 минут
        $immersionNode = $maxTree->findNode('immersion');
        $this->assertNotNull($immersionNode);
        $this->assertSame(17280, $immersionNode->default->points[0]->timeInMinutes);

        // immersion.esi.default.points[0] = 10 дней = 14400 минут
        // ESI ключ может зависеть от case-normalize; пробуем оба варианта
        $esiNode = $maxTree->findNode('immersion', 'esi') ?? $maxTree->findNode('immersion', 'ESI');
        $this->assertNotNull($esiNode, 'esi branch должен существовать');
        $this->assertSame(14400, $esiNode->default->points[0]->timeInMinutes);
    }
}
