<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Infrastructure\Api\NetAngels;

use App\Inquiries\Infrastructure\Api\NetAngels\ExtractionResponseParser;
use PHPUnit\Framework\TestCase;

final class ExtractionResponseParserTest extends TestCase
{
    public function test_parses_valid_fields_with_temperature_objects(): void
    {
        ['parameters' => $p, 'dropped' => $dropped] = (new ExtractionResponseParser())->parse(json_encode([
            'substrates' => ['steel_carbon'],
            'environment' => 'special',
            'specifiedCoatings' => ['ЛТ Плюс'],
            'search' => 'пропарка',
            'chemicalResistance' => [
                'substances' => ['дизельное топливо'],
                'maxTemperature' => 40,
                'peakTemperature' => 90,
            ],
            'thermal' => [
                'maxTemperature' => null,
                'peakTemperature' => null,
                'environment' => null,
            ],
            'durability' => 'MEDIUM',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['steel_carbon'], $p->substrates);
        self::assertSame('special', $p->environment);
        self::assertSame(['ЛТ Плюс'], $p->specifiedCoatings);
        self::assertSame('пропарка', $p->search);
        self::assertSame(['дизельное топливо'], $p->chemicalResistance->substances);
        self::assertSame(40, $p->chemicalResistance->maxTemperature);
        self::assertSame(90, $p->chemicalResistance->peakTemperature);
        self::assertNull($p->thermal->maxTemperature);
        self::assertNull($p->thermal->environment);
        self::assertSame('MEDIUM', $p->durability);
        self::assertSame([], $dropped);
    }

    public function test_parses_thermal_without_chemistry(): void
    {
        ['parameters' => $p] = (new ExtractionResponseParser())->parse(json_encode([
            'chemicalResistance' => ['substances' => [], 'maxTemperature' => null, 'peakTemperature' => null],
            'thermal' => ['maxTemperature' => 150, 'peakTemperature' => 200, 'environment' => 'dry_heat'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $p->chemicalResistance->substances);
        self::assertSame(150, $p->thermal->maxTemperature);
        self::assertSame(200, $p->thermal->peakTemperature);
        self::assertSame('dry_heat', $p->thermal->environment);
    }

    public function test_drops_values_outside_enum(): void
    {
        ['parameters' => $p, 'dropped' => $dropped] = (new ExtractionResponseParser())->parse(json_encode([
            'substrates' => ['steel_carbon', 'bogus_metal'],
            'environment' => 'nowhere',
            'durability' => 'SUPER',
            'thermal' => ['environment' => 'lava'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['steel_carbon'], $p->substrates);
        self::assertNull($p->environment);
        self::assertNull($p->durability);
        self::assertNull($p->thermal->environment);
        self::assertNotEmpty($dropped);
    }

    public function test_invalid_json_yields_empty_with_note(): void
    {
        ['parameters' => $p, 'dropped' => $dropped] = (new ExtractionResponseParser())->parse('not a json');

        self::assertSame([], $p->substrates);
        self::assertSame([], $p->chemicalResistance->substances);
        self::assertNull($p->thermal->maxTemperature);
        self::assertCount(1, $dropped);
    }

    public function test_temperature_out_of_range_dropped(): void
    {
        ['parameters' => $p, 'dropped' => $dropped] = (new ExtractionResponseParser())->parse(json_encode([
            'chemicalResistance' => ['substances' => [], 'maxTemperature' => 5000, 'peakTemperature' => null],
        ], JSON_THROW_ON_ERROR));

        self::assertNull($p->chemicalResistance->maxTemperature);
        self::assertNotEmpty($dropped);
    }

    public function test_partial_application_range_kept(): void
    {
        ['parameters' => $p] = (new ExtractionResponseParser())->parse(json_encode([
            'applicationMinTemp' => ['from' => -10, 'to' => null],
        ], JSON_THROW_ON_ERROR));

        self::assertNotNull($p->applicationMinTemp);
        self::assertSame(-10, $p->applicationMinTemp->from);
        self::assertNull($p->applicationMinTemp->to);
    }
}
