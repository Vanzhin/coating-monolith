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
            'tagIds', 'coatingIds', 'applicationMinTemp', 'minApplicationTimeAt20Hours',
            'sort', 'page', 'perPage', 'sortOptions', 'substrateOptions', 'environmentOptions', 'standardOptions',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        self::assertSame('abc', $payload['q']);
        self::assertSame(['from' => 2, 'to' => 0], $payload['minApplicationTimeAt20Hours']);
    }
}
