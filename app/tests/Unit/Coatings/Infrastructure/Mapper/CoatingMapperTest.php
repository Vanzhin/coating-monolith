<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use PHPUnit\Framework\TestCase;

final class CoatingMapperTest extends TestCase
{
    private CoatingMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoatingMapper();
    }

    public function test_parse_duration_from_input_adds_up_days_hours_minutes(): void
    {
        // 1 день + 2 часа + 30 минут = 24*60 + 120 + 30 = 1590 мин
        $totalMinutes = $this->mapper->parseDurationInput(['days' => 1, 'hours' => 2, 'minutes' => 30]);
        $this->assertSame(1590, $totalMinutes);
    }

    public function test_parse_duration_from_input_accepts_empty_as_zero(): void
    {
        $this->assertSame(0, $this->mapper->parseDurationInput([]));
        $this->assertSame(0, $this->mapper->parseDurationInput(['days' => '', 'hours' => '', 'minutes' => '']));
    }

    public function test_decompose_minutes_into_days_hours_minutes(): void
    {
        $this->assertSame(
            ['days' => 1, 'hours' => 2, 'minutes' => 30],
            $this->mapper->decomposeDurationForForm(1590),
        );
        $this->assertSame(
            ['days' => 0, 'hours' => 0, 'minutes' => 12],
            $this->mapper->decomposeDurationForForm(12),
        );
        $this->assertSame(
            ['days' => 10, 'hours' => 0, 'minutes' => 0],
            $this->mapper->decomposeDurationForForm(14400),
        );
    }

    public function test_recoating_interval_nested_round_trip(): void
    {
        $mapper = new CoatingMapper();

        $input = $this->validInput([
            'minRecoatingInterval' => [
                'default' => [
                    'points' => [
                        ['temperature_at' => 20, 'days' => 0, 'hours' => 4, 'minutes' => 0],
                    ],
                ],
                'branches' => [
                    'atmospheric' => [
                        'default' => [
                            'points' => [
                                ['temperature_at' => 20, 'days' => 0, 'hours' => 3, 'minutes' => 0],
                            ],
                        ],
                        'branches' => [
                            'ep' => [
                                'default' => [
                                    'points' => [
                                        ['temperature_at' => 20, 'days' => 0, 'hours' => 2, 'minutes' => 0],
                                    ],
                                ],
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

        $dto = $mapper->buildCoatingDtoFromInputData($input);

        $this->assertInstanceOf(RecoatingIntervalTreeDTO::class, $dto->minRecoatingInterval);
        $this->assertSame(4 * 60, $dto->minRecoatingInterval->default[0]->time_in_minutes);
        $this->assertArrayHasKey('atmospheric', $dto->minRecoatingInterval->branches);
        $this->assertSame(3 * 60, $dto->minRecoatingInterval->branches['atmospheric']->default[0]->time_in_minutes);
        $this->assertArrayHasKey('ep', $dto->minRecoatingInterval->branches['atmospheric']->branches);
        $this->assertSame(
            2 * 60,
            $dto->minRecoatingInterval->branches['atmospheric']->branches['ep']->default[0]->time_in_minutes,
        );
        $this->assertNull($dto->maxRecoatingInterval);

        // Back to array form.
        $reInput = $mapper->buildInputDataFromDto($dto);
        $this->assertSame(20, $reInput['minRecoatingInterval']['default']['points'][0]['temperature_at']);
        $this->assertSame(4 * 60, $reInput['minRecoatingInterval']['default']['points'][0]['time_in_minutes']);
        $this->assertArrayHasKey(
            'ep',
            $reInput['minRecoatingInterval']['branches']['atmospheric']['branches'],
        );
    }

    public function test_recoating_interval_missing_from_input_defaults_to_empty_node(): void
    {
        $mapper = new CoatingMapper();
        $dto = $mapper->buildCoatingDtoFromInputData($this->validInput([]));

        $this->assertInstanceOf(RecoatingIntervalTreeDTO::class, $dto->minRecoatingInterval);
        $this->assertSame([], $dto->minRecoatingInterval->default);
        $this->assertSame([], $dto->minRecoatingInterval->branches);
        $this->assertNull($dto->maxRecoatingInterval);
    }

    public function test_builds_unlimited_from_kind_attribute(): void
    {
        $mapper = $this->makeMapper();
        $input = $this->minimalInput([
            'maxRecoatingInterval' => [
                'default' => ['points' => [[
                    'temperature_at' => 20,
                    'kind' => 'unlimited',
                    'days' => 0, 'hours' => 0, 'minutes' => 0,
                ]]],
                'branches' => [],
            ],
        ]);

        $dto = $mapper->buildCoatingDtoFromInputData($input);

        $this->assertNotNull($dto->maxRecoatingInterval);
        $this->assertCount(1, $dto->maxRecoatingInterval->default);
        $this->assertSame(0, $dto->maxRecoatingInterval->default[0]->time_in_minutes);
    }

    public function test_builds_unknown_from_kind_attribute(): void
    {
        $mapper = $this->makeMapper();
        $input = $this->minimalInput([
            'maxRecoatingInterval' => [
                'default' => ['points' => [[
                    'temperature_at' => 20,
                    'kind' => 'unknown',
                    'days' => 0, 'hours' => 0, 'minutes' => 0,
                ]]],
                'branches' => [],
            ],
        ]);

        $dto = $mapper->buildCoatingDtoFromInputData($input);

        $this->assertNotNull($dto->maxRecoatingInterval);
        $this->assertCount(1, $dto->maxRecoatingInterval->default);
        $this->assertNull($dto->maxRecoatingInterval->default[0]->time_in_minutes);
    }

    public function test_legacy_zero_duration_becomes_unknown(): void
    {
        // Без явного kind: дни/часы/минуты все 0 → точка имеет time_in_minutes = null.
        // Это безопасный дефолт для старых форм: «юзер ничего не ввёл, не подменяем на unlimited».
        $mapper = $this->makeMapper();
        $input = $this->minimalInput([
            'maxRecoatingInterval' => [
                'default' => ['points' => [[
                    'temperature_at' => 20,
                    'days' => 0, 'hours' => 0, 'minutes' => 0,
                ]]],
                'branches' => [],
            ],
        ]);

        $dto = $mapper->buildCoatingDtoFromInputData($input);

        $this->assertNotNull($dto->maxRecoatingInterval);
        $this->assertCount(1, $dto->maxRecoatingInterval->default);
        $this->assertNull($dto->maxRecoatingInterval->default[0]->time_in_minutes);
    }

    public function test_decompose_adds_kind_for_form_roundtrip(): void
    {
        $mapper = $this->makeMapper();

        // Серия из трёх точек разных kind.
        $duration = new DryingTimePointDTO();
        $duration->temperature_at = 10;
        $duration->time_in_minutes = 720;

        $unlimited = new DryingTimePointDTO();
        $unlimited->temperature_at = 20;
        $unlimited->time_in_minutes = 0;

        $unknown = new DryingTimePointDTO();
        $unknown->temperature_at = 30;
        $unknown->time_in_minutes = null;

        $decomposeMethod = new \ReflectionMethod($mapper, 'decomposeSeriesForForm');
        $decomposeMethod->setAccessible(true);
        $form = $decomposeMethod->invoke($mapper, [$duration, $unlimited, $unknown]);

        $this->assertSame('duration', $form[0]['kind']);
        $this->assertSame('unlimited', $form[1]['kind']);
        $this->assertSame('unknown', $form[2]['kind']);
        $this->assertSame(720, $form[0]['time_in_minutes']);
        $this->assertSame(0, $form[1]['time_in_minutes']);
        $this->assertNull($form[2]['time_in_minutes']);
    }

    /** @param array<string, mixed> $overrides */
    private function validInput(array $overrides): array
    {
        return array_merge([
            'title' => 'X', 'description' => 'desc',
            'volumeSolid' => 50, 'massDensity' => 1.2,
            'base' => 'EP',
            'minDft' => 80, 'maxDft' => 150, 'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'dryToTouch' => [['temperature_at' => 20, 'days' => 0, 'hours' => 1, 'minutes' => 0]],
            'fullCure' => [['temperature_at' => 20, 'days' => 1, 'hours' => 0, 'minutes' => 0]],
            'pack' => 1.0, 'thinner' => null,
            'manufacturer' => ['id' => '00000000-0000-0000-0000-000000000001'],
            'tags' => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function minimalInput(array $overrides): array
    {
        return array_merge([
            'title' => 'Test', 'description' => 'Test desc',
            'volumeSolid' => 50, 'massDensity' => 1.2,
            'base' => 'EP',
            'minDft' => 80, 'maxDft' => 150, 'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'dryToTouch' => [['temperature_at' => 20, 'days' => 0, 'hours' => 1, 'minutes' => 0]],
            'fullCure' => [['temperature_at' => 20, 'days' => 1, 'hours' => 0, 'minutes' => 0]],
            'pack' => 1.0, 'thinner' => null,
            'manufacturer' => ['id' => '00000000-0000-0000-0000-000000000001'],
            'tags' => [],
        ], $overrides);
    }

    private function makeMapper(): CoatingMapper
    {
        return new CoatingMapper();
    }
}
