<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query;

use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommandHandler;
use App\Coatings\Application\UseCase\Query\ListSurfaceTreatments\ListSurfaceTreatmentsQuery;
use App\Coatings\Application\UseCase\Query\ListSurfaceTreatments\ListSurfaceTreatmentsQueryHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListSurfaceTreatmentsTest extends KernelTestCase
{
    private ListSurfaceTreatmentsQueryHandler $queryHandler;
    private CreateSurfaceTreatmentCommandHandler $createHandler;
    private EntityManagerInterface $em;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->queryHandler = $c->get(ListSurfaceTreatmentsQueryHandler::class);
        $this->createHandler = $c->get(CreateSurfaceTreatmentCommandHandler::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->testPrefix = 'LST-'.substr(uniqid('', true), -8);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        parent::tearDown();
    }

    public function test_filter_by_substrate_scope(): void
    {
        $t1 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-2.5',
            standardCode: 'ISO 12944',
            description: 'Blast cleaning of steel',
            substrateScope: [Substrate::STEEL_CARBON],
        );
        $t2 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-3',
            standardCode: 'ISO 12944',
            description: 'Very thorough blast cleaning',
            substrateScope: [Substrate::STEEL_GALVANIZED],
        );
        $t3 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-G',
            standardCode: null,
            description: 'Grit blasting for concrete',
            substrateScope: [Substrate::CONCRETE],
        );

        ($this->createHandler)($t1);
        ($this->createHandler)($t2);
        ($this->createHandler)($t3);
        $this->em->clear();

        $result = ($this->queryHandler)(new ListSurfaceTreatmentsQuery(
            filter: new SurfaceTreatmentsFilter(
                substrate: Substrate::STEEL_CARBON,
                q: $this->testPrefix,
            ),
            page: 1,
            perPage: 20,
        ));

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
        self::assertSame($this->testPrefix.'-2.5', $result['items'][0]->code);
    }

    public function test_filter_by_search_query(): void
    {
        $t1 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-2.5',
            standardCode: 'ISO 12944',
            description: 'Blast cleaning',
            substrateScope: [Substrate::STEEL_CARBON],
        );
        $t2 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-3',
            standardCode: 'ISO 12944',
            description: 'Very thorough preparation',
            substrateScope: [Substrate::STEEL_GALVANIZED],
        );
        $t3 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-G',
            standardCode: null,
            description: 'Grit blasting',
            substrateScope: [Substrate::CONCRETE],
        );

        ($this->createHandler)($t1);
        ($this->createHandler)($t2);
        ($this->createHandler)($t3);
        $this->em->clear();

        $result = ($this->queryHandler)(new ListSurfaceTreatmentsQuery(
            filter: new SurfaceTreatmentsFilter(q: $this->testPrefix),
            page: 1,
            perPage: 20,
        ));

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['items']);

        $codes = array_map(fn ($dto) => $dto->code, $result['items']);
        self::assertContains($this->testPrefix.'-2.5', $codes);
        self::assertContains($this->testPrefix.'-3', $codes);
    }

    public function test_filter_substrate_and_search_combined(): void
    {
        $t1 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-2.5',
            standardCode: 'ISO 12944',
            description: 'Blast cleaning',
            substrateScope: [Substrate::STEEL_CARBON],
        );
        $t2 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-3',
            standardCode: 'ISO 12944',
            description: 'Very thorough preparation',
            substrateScope: [Substrate::STEEL_GALVANIZED],
        );
        $t3 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-G',
            standardCode: null,
            description: 'Grit blasting',
            substrateScope: [Substrate::STEEL_CARBON],
        );

        ($this->createHandler)($t1);
        ($this->createHandler)($t2);
        ($this->createHandler)($t3);
        $this->em->clear();

        $result = ($this->queryHandler)(new ListSurfaceTreatmentsQuery(
            filter: new SurfaceTreatmentsFilter(
                substrate: Substrate::STEEL_CARBON,
                q: $this->testPrefix,
            ),
            page: 1,
            perPage: 20,
        ));

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['items']);
        $codes = array_map(fn ($dto) => $dto->code, $result['items']);
        self::assertContains($this->testPrefix.'-2.5', $codes);
    }

    public function test_empty_filter_returns_all_with_pagination(): void
    {
        $t1 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-2.5',
            standardCode: 'ISO 12944',
            description: 'Blast cleaning',
            substrateScope: [Substrate::STEEL_CARBON],
        );
        $t2 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-3',
            standardCode: 'ISO 12944',
            description: 'Very thorough preparation',
            substrateScope: [Substrate::STEEL_GALVANIZED],
        );
        $t3 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-G',
            standardCode: null,
            description: 'Grit blasting',
            substrateScope: [Substrate::CONCRETE],
        );

        ($this->createHandler)($t1);
        ($this->createHandler)($t2);
        ($this->createHandler)($t3);
        $this->em->clear();

        $result = ($this->queryHandler)(new ListSurfaceTreatmentsQuery(
            filter: new SurfaceTreatmentsFilter(q: $this->testPrefix),
            page: 1,
            perPage: 20,
        ));

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['items']);
    }

    public function test_pagination(): void
    {
        $t1 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-2.5',
            standardCode: 'ISO 12944',
            description: 'Blast cleaning',
            substrateScope: [Substrate::STEEL_CARBON],
        );
        $t2 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-3',
            standardCode: 'ISO 12944',
            description: 'Very thorough preparation',
            substrateScope: [Substrate::STEEL_GALVANIZED],
        );
        $t3 = new CreateSurfaceTreatmentCommand(
            code: $this->testPrefix.'-G',
            standardCode: null,
            description: 'Grit blasting',
            substrateScope: [Substrate::CONCRETE],
        );

        ($this->createHandler)($t1);
        ($this->createHandler)($t2);
        ($this->createHandler)($t3);
        $this->em->clear();

        $page1 = ($this->queryHandler)(new ListSurfaceTreatmentsQuery(
            filter: new SurfaceTreatmentsFilter(q: $this->testPrefix),
            page: 1,
            perPage: 1,
        ));

        self::assertSame(3, $page1['total']);
        self::assertCount(1, $page1['items']);

        $page2 = ($this->queryHandler)(new ListSurfaceTreatmentsQuery(
            filter: new SurfaceTreatmentsFilter(q: $this->testPrefix),
            page: 2,
            perPage: 1,
        ));

        self::assertSame(3, $page2['total']);
        self::assertCount(1, $page2['items']);

        $page1Id = $page1['items'][0]->id;
        $page2Id = $page2['items'][0]->id;
        self::assertNotSame($page1Id, $page2Id, 'Page 2 must have different item than Page 1.');
    }
}
