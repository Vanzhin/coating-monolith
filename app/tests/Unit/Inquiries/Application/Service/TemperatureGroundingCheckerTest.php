<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Application\Service;

use App\Inquiries\Application\DTO\ChemicalResistanceParams;
use App\Inquiries\Application\DTO\ExtractedParameters;
use App\Inquiries\Application\DTO\IntRangeDto;
use App\Inquiries\Application\DTO\ThermalParams;
use App\Inquiries\Application\Service\TemperatureGroundingChecker;
use PHPUnit\Framework\TestCase;

final class TemperatureGroundingCheckerTest extends TestCase
{
    public function test_temperature_present_in_text_is_silent(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemMax: 90),
            'рабочая температура продукта 90 °C',
        );

        self::assertSame([], $warnings);
    }

    public function test_temperature_absent_warns(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemMax: 80),
            'нужна система для нефти, погружение',
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString('80 °C', $warnings[0]);
        self::assertStringContainsString('рабочая у вещества', $warnings[0]);
    }

    public function test_number_inside_bigger_number_does_not_count(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemMax: 90),
            'артикул 1908, резервуар РВС-1908',
        );

        self::assertCount(1, $warnings); // 90 внутри 1908 — не совпадение
    }

    public function test_negative_grounded_by_digits(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(appFrom: -10),
            'температура нанесения не ниже -10 °C',
        );

        self::assertSame([], $warnings);
    }

    public function test_multiple_fields_mixed(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemMax: 40, thMax: 150),
            'рабочая 40, а про 150 в тексте ни слова... стоп, 150 тут есть',
        );

        // обе (40 и 150) присутствуют в тексте → молчим
        self::assertSame([], $warnings);
    }

    public function test_only_absent_field_warns(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemMax: 40, thPeak: 300),
            'рабочая температура 40 °C',
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString('300 °C', $warnings[0]);
        self::assertStringContainsString('пиковая нагрева', $warnings[0]);
    }

    public function test_decimal_in_text_does_not_ground_integer(): void
    {
        // «толщина 4,0 мм» НЕ должна заземлить выдуманную температуру 4
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(thMax: 4),
            'резервуар, толщина стенки 4,0 мм, антикоррозия',
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString('4 °C', $warnings[0]);
    }

    public function test_sentence_period_still_grounds(): void
    {
        // точка в конце предложения (не десятичная) заземление не ломает
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemMax: 90),
            'рабочая температура продукта 90.',
        );

        self::assertSame([], $warnings);
    }

    public function test_chem_peak_and_application_to_fields(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemPeak: 120, appTo: 35),
            'пиковая 120 °C, наносить до 35 °C',
        );

        self::assertSame([], $warnings);

        $absent = (new TemperatureGroundingChecker())->check(
            $this->params(chemPeak: 120, appTo: 35),
            'пиковая 120 °C',
        );
        self::assertCount(1, $absent);
        self::assertStringContainsString('нанесение, до', $absent[0]);
    }

    public function test_null_fields_ignored(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check($this->params(), 'без температур');

        self::assertSame([], $warnings);
    }

    public function test_temperature_from_attachment_section_is_grounded(): void
    {
        $warnings = (new TemperatureGroundingChecker())->check(
            $this->params(chemMax: 80),
            "=== Тело письма ===\nнужна антикоррозия\n\n=== Вложение: опросник.pdf ===\nрабочая температура 80",
        );

        self::assertSame([], $warnings);
    }

    private function params(
        ?int $chemMax = null,
        ?int $chemPeak = null,
        ?int $thMax = null,
        ?int $thPeak = null,
        ?int $appFrom = null,
        ?int $appTo = null,
    ): ExtractedParameters {
        return new ExtractedParameters(
            substrates: [],
            environment: null,
            specifiedCoatings: [],
            search: null,
            chemicalResistance: new ChemicalResistanceParams([], $chemMax, $chemPeak),
            thermal: new ThermalParams($thMax, $thPeak, null),
            standard: null,
            category: null,
            durability: null,
            applicationMinTemp: (null !== $appFrom || null !== $appTo) ? new IntRangeDto($appFrom, $appTo) : null,
            minApplicationTimeAt20: null,
        );
    }
}
