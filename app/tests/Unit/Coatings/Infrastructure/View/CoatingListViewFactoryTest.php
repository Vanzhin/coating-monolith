<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\View;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQueryResult;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Coatings\Infrastructure\View\CoatingListViewFactory;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingListViewFactoryTest extends TestCase
{
    public function test_build_returns_expected_payload_keys(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $queryBus->method('execute')->willReturn(new GetPagedManufacturersQueryResult([], new Pager(1, 1000)));

        $tagRepo = $this->createMock(TagRepositoryInterface::class);
        $tagRepo->method('findByIds')->willReturn([]);

        $factory = new CoatingListViewFactory(
            $queryBus,
            $tagRepo,
            new TagDTOTransformer(),
            new QueryParams(),
        );

        $env = ThermalEnvironment::cases()[0];

        $result = new GetPagedCoatingsQueryResult([], new Pager(1, 20));
        $payload = $factory->build(Request::create('/', 'GET', [
            'appMinTempFrom' => '5',
            'thermEnv' => $env->value,
            'thermPeak' => '1',
        ]), $result, null);

        foreach ([
            'search', 'selectedManufacturerIds', 'selectedTags', 'selectedBaseValues',
            'manufacturers', 'result', 'error', 'coatingBases',
            'appMinTempPresets', 'volumeSolidPresets', 'minRecoat20Presets', 'maxRecoat20Presets',
            'appMinTempFrom', 'appMinTempTo', 'volumeSolidFrom', 'volumeSolidTo',
            'minRecoat20From', 'minRecoat20To', 'maxRecoat20From', 'maxRecoat20To',
            'thermTemp', 'thermEnv', 'thermIncludingPeak', 'sort', 'sortOptions', 'preservedParams',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        self::assertSame(5, $payload['appMinTempFrom']);
        self::assertSame($env->value, $payload['thermEnv']);
        self::assertTrue($payload['thermIncludingPeak']);
        self::assertArrayNotHasKey('search', $payload['preservedParams']);
    }
}
