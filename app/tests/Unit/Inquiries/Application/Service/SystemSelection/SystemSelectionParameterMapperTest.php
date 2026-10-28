<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Application\Service\SystemSelection;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Inquiries\Application\DTO\ChemicalResistanceParams;
use App\Inquiries\Application\DTO\ExtractedParameters;
use App\Inquiries\Application\DTO\IntRangeDto;
use App\Inquiries\Application\DTO\ThermalParams;
use App\Inquiries\Application\Port\SubstanceResolver;
use App\Inquiries\Application\Service\SystemSelection\SystemSelectionParameterMapper;
use PHPUnit\Framework\TestCase;

final class SystemSelectionParameterMapperTest extends TestCase
{
    public function test_maps_axes_resolves_substances_and_warns(): void
    {
        $mapper = new SystemSelectionParameterMapper($this->resolver());
        $mapped = $mapper->map($this->params());
        $in = $mapped->input;

        self::assertSame(['steel_carbon'], $in->substrates);
        self::assertSame('immersion', $in->environment);
        self::assertSame('пропарка', $in->search);
        self::assertSame(['sub-eth'], $in->substanceIds);
        self::assertSame(40, $in->substanceTemperature);
        self::assertSame(120, $in->thermalTemperature); // задан пик 120 → он и есть лимит (пиковая колонка)
        self::assertSame('dry_heat', $in->thermalEnvironment);
        self::assertTrue($in->thermalIncludingPeak);
        self::assertSame('iso12944', $in->standard);
        self::assertSame('C5', $in->category);
        self::assertSame('high', $in->durability);
        self::assertSame(5, $in->applicationMinTempFrom);
        self::assertNull($in->applicationMinTempTo);
        self::assertSame(120, $in->minApplicationTimeFromMinutes);

        // нерезолв «уайт-спирит» + покрытие заказчика «Интергард 475» + рабочая темп. не вошла (есть пик)
        self::assertCount(3, $mapped->warnings);
        self::assertTrue($this->anyContains($mapped->warnings, 'уайт-спирит'));
        self::assertTrue($this->anyContains($mapped->warnings, 'Интергард 475'));
        self::assertTrue($this->anyContains($mapped->warnings, 'рабочая 60'));
    }

    public function test_resolves_multiple_substances_and_sets_answer_limit(): void
    {
        $resolver = new class implements SubstanceResolver {
            public function resolve(string $name): ?string
            {
                return match ($name) {
                    'этанол' => 'sub-eth',
                    'метанол' => 'sub-met',
                    default => null,
                };
            }
        };
        $params = new ExtractedParameters(
            substrates: [],
            environment: null,
            specifiedCoatings: [],
            search: null,
            chemicalResistance: new ChemicalResistanceParams(['этанол', 'метанол'], null, null),
            thermal: new ThermalParams(null, null, null),
            standard: null,
            category: null,
            durability: null,
            applicationMinTemp: null,
            minApplicationTimeAt20: null,
        );

        $mapped = (new SystemSelectionParameterMapper($resolver))->map($params);

        self::assertSame(['sub-eth', 'sub-met'], $mapped->input->substanceIds);
        self::assertSame(50, $mapped->input->limit);
        self::assertSame([], $mapped->warnings);
    }

    public function test_deduplicates_substance_ids_and_names(): void
    {
        $resolver = new class implements SubstanceResolver {
            public function resolve(string $name): ?string
            {
                // разные формулировки одного вещества + дубль имени → один id
                return in_array($name, ['нефть', 'сырая нефть'], true) ? 'sub-oil' : null;
            }
        };
        $params = new ExtractedParameters(
            substrates: [],
            environment: null,
            specifiedCoatings: [],
            search: null,
            chemicalResistance: new ChemicalResistanceParams(['нефть', 'сырая нефть', 'нефть'], null, null),
            thermal: new ThermalParams(null, null, null),
            standard: null,
            category: null,
            durability: null,
            applicationMinTemp: null,
            minApplicationTimeAt20: null,
        );

        $mapped = (new SystemSelectionParameterMapper($resolver))->map($params);

        self::assertSame(['sub-oil'], $mapped->input->substanceIds);
    }

    public function test_mapped_input_builds_a_real_search_query(): void
    {
        // Непрозрачные параметры обязаны ре-прогоняться поиском: примитивы → фильтр Coatings без исключений.
        $mapped = (new SystemSelectionParameterMapper($this->resolver()))->map($this->params());

        $query = SearchCoatingSystemsQuery::fromParameters(
            CoatingSystemsFilterInput::fromArray($mapped->input->toArray()),
        );

        self::assertInstanceOf(SearchCoatingSystemsQuery::class, $query);
    }

    public function test_no_peak_means_not_including_peak(): void
    {
        $params = new ExtractedParameters(
            substrates: [],
            environment: null,
            specifiedCoatings: [],
            search: null,
            chemicalResistance: new ChemicalResistanceParams([], null, null),
            thermal: new ThermalParams(60, null, 'dry_heat'),
            standard: null,
            category: null,
            durability: null,
            applicationMinTemp: null,
            minApplicationTimeAt20: null,
        );

        $mapped = (new SystemSelectionParameterMapper($this->resolver()))->map($params);

        self::assertSame(60, $mapped->input->thermalTemperature); // пика нет → рабочая (непрерывная колонка)
        self::assertFalse($mapped->input->thermalIncludingPeak);
        self::assertSame([], $mapped->warnings);
    }

    private function params(): ExtractedParameters
    {
        return new ExtractedParameters(
            substrates: ['steel_carbon'],
            environment: 'immersion',
            specifiedCoatings: ['Интергард 475'],
            search: 'пропарка',
            chemicalResistance: new ChemicalResistanceParams(['этанол', 'уайт-спирит'], 40, 90),
            thermal: new ThermalParams(60, 120, 'dry_heat'),
            standard: 'iso12944',
            category: 'C5',
            durability: 'high',
            applicationMinTemp: new IntRangeDto(5, null),
            minApplicationTimeAt20: new IntRangeDto(120, null),
        );
    }

    private function resolver(): SubstanceResolver
    {
        return new class implements SubstanceResolver {
            public function resolve(string $name): ?string
            {
                return 'этанол' === $name ? 'sub-eth' : null;
            }
        };
    }

    /** @param list<string> $warnings */
    private function anyContains(array $warnings, string $needle): bool
    {
        foreach ($warnings as $w) {
            if (str_contains($w, $needle)) {
                return true;
            }
        }

        return false;
    }
}
