<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\View;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Compliance\ComplianceFacetsRegistry;
use App\Coatings\Domain\Compliance\Iso12944\Iso12944Evaluator;
use App\Coatings\Domain\Compliance\Sp28\Sp28Evaluator;
use App\Coatings\Infrastructure\View\CoatingSystemListViewFactory;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingSystemListViewFactoryTest extends TestCase
{
    public function test_build_payload_keys_and_hours_conversion(): void
    {
        $factory = new CoatingSystemListViewFactory(
            new QueryParams(),
            new ComplianceFacetsRegistry([new Iso12944Evaluator(), new Sp28Evaluator()]),
        );
        $result = new SearchCoatingSystemsQueryResult([], 0);

        $payload = $factory->build(
            Request::create('/', 'GET', ['minApplicationTimeAt20From' => '2', 'q' => 'abc']),
            $result,
        );

        foreach ([
            'items', 'total', 'q', 'substrates', 'environment', 'standard', 'category', 'durability',
            'tagIds', 'coatingIds', 'substanceIds', 'substanceTemperature', 'applicationMinTemp', 'minApplicationTimeAt20Hours',
            'sort', 'page', 'perPage', 'sortOptions', 'substrateOptions', 'environmentOptions', 'standardOptions',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        self::assertSame('abc', $payload['q']);
        self::assertSame(['from' => 2, 'to' => 0], $payload['minApplicationTimeAt20Hours']);
    }

    public function test_substance_facet_echoed_for_chip_and_field_hydration(): void
    {
        $factory = new CoatingSystemListViewFactory(
            new QueryParams(),
            new ComplianceFacetsRegistry([new Iso12944Evaluator(), new Sp28Evaluator()]),
        );
        $result = new SearchCoatingSystemsQueryResult([], 0);

        $payload = $factory->build(
            Request::create('/', 'GET', [
                'substanceIds' => ['11111111-1111-1111-8111-111111111111', 'garbage'],
                'substanceTemp' => '60',
            ]),
            $result,
        );

        self::assertSame(['11111111-1111-1111-8111-111111111111'], $payload['substanceIds']);
        self::assertSame(60, $payload['substanceTemperature']);
    }

    public function test_search_param_echoed_with_q_fallback(): void
    {
        $factory = new CoatingSystemListViewFactory(
            new QueryParams(),
            new ComplianceFacetsRegistry([new Iso12944Evaluator(), new Sp28Evaluator()]),
        );
        $result = new SearchCoatingSystemsQueryResult([], 0);

        $payload = $factory->build(Request::create('/', 'GET', ['q' => 'эпокси']), $result);

        self::assertSame('эпокси', $payload['q']);
    }
}
