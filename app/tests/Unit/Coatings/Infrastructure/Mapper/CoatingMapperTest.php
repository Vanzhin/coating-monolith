<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use PHPUnit\Framework\TestCase;

class CoatingMapperTest extends TestCase
{
    private CoatingMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoatingMapper();
    }

    public function testParseDurationFromInputAddsUpDaysHoursMinutes(): void
    {
        // 1 день + 2 часа + 30 минут = 24*60 + 120 + 30 = 1590 мин
        $totalMinutes = $this->mapper->parseDurationInput(['days' => 1, 'hours' => 2, 'minutes' => 30]);
        $this->assertSame(1590, $totalMinutes);
    }

    public function testParseDurationFromInputAcceptsEmptyAsZero(): void
    {
        $this->assertSame(0, $this->mapper->parseDurationInput([]));
        $this->assertSame(0, $this->mapper->parseDurationInput(['days' => '', 'hours' => '', 'minutes' => '']));
    }

    public function testDecomposeMinutesIntoDaysHoursMinutes(): void
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

    public function testBuildDtoSplitsRecoatingIntoMinAndMaxSeries(): void
    {
        $input = [
            'id' => null,
            'title' => 'T',
            'description' => 'D',
            'volumeSolid' => 60,
            'massDensity' => 1.3,
            'base' => 'EP',
            'minDft' => 80, 'maxDft' => 120, 'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'pack' => 20,
            'thinner' => null,
            'manufacturer' => ['id' => '11111111-1111-4111-8111-111111111111'],
            'tags' => [],
            'dryToTouch' => [['temperature_at' => 20, 'days' => 0, 'hours' => 0, 'minutes' => 12]],
            'fullCure'   => [['temperature_at' => 20, 'days' => 0, 'hours' => 7, 'minutes' => 0]],
            'minRecoatingInterval' => [
                ['temperature_at' => 20, 'days' => 0, 'hours' => 12, 'minutes' => 0],
                ['temperature_at' => 5,  'days' => 1, 'hours' => 0,  'minutes' => 0],
            ],
            'maxRecoatingInterval' => [
                ['temperature_at' => 20, 'days' => 3, 'hours' => 0, 'minutes' => 0],
                ['temperature_at' => 5,  'days' => 7, 'hours' => 0, 'minutes' => 0],
            ],
        ];

        $dto = $this->mapper->buildCoatingDtoFromInputData($input);

        $this->assertCount(2, $dto->minRecoatingInterval);
        $this->assertSame(20,  $dto->minRecoatingInterval[0]->temperature_at);
        $this->assertSame(720, $dto->minRecoatingInterval[0]->time_in_minutes);
        $this->assertSame(1440, $dto->minRecoatingInterval[1]->time_in_minutes);

        $this->assertNotNull($dto->maxRecoatingInterval);
        $this->assertCount(2, $dto->maxRecoatingInterval);
        $this->assertSame(4320,  $dto->maxRecoatingInterval[0]->time_in_minutes);
        $this->assertSame(10080, $dto->maxRecoatingInterval[1]->time_in_minutes);
    }

    public function testEmptyMaxRecoatingMeansNoUpperBound(): void
    {
        $input = $this->minimalInput();
        $input['maxRecoatingInterval'] = []; // или не указано
        $dto = $this->mapper->buildCoatingDtoFromInputData($input);
        $this->assertNull($dto->maxRecoatingInterval);
    }

    public function testDecomposeRoundtripPreservesShape(): void
    {
        $dto = $this->mapperDto([
            'minRecoatingInterval' => [
                $this->point(20, 720),  // 12 ч
                $this->point(5, 4320),  // 3 сут
            ],
            'maxRecoatingInterval' => null, // нет верхней границы
        ]);

        $form = $this->mapper->buildInputDataFromDto($dto);

        $this->assertSame(20, $form['minRecoatingInterval'][0]['temperature_at']);
        $this->assertSame(['days' => 0, 'hours' => 12, 'minutes' => 0], $this->onlyDhm($form['minRecoatingInterval'][0]));
        $this->assertSame(['days' => 3, 'hours' => 0, 'minutes' => 0], $this->onlyDhm($form['minRecoatingInterval'][1]));
        $this->assertSame([], $form['maxRecoatingInterval']); // null → пустой массив в форме (= нет строк)
    }

    /** @return array<string, mixed> */
    private function minimalInput(): array
    {
        return [
            'id' => null,
            'title' => 'T', 'description' => 'D',
            'volumeSolid' => 60, 'massDensity' => 1.3,
            'base' => 'EP',
            'minDft' => 80, 'maxDft' => 120, 'tdsDft' => 100,
            'applicationMinTemp' => 5, 'pack' => 20, 'thinner' => null,
            'manufacturer' => ['id' => '11111111-1111-4111-8111-111111111111'],
            'tags' => [],
            'dryToTouch' => [['temperature_at' => 20, 'days' => 0, 'hours' => 0, 'minutes' => 12]],
            'fullCure'   => [['temperature_at' => 20, 'days' => 0, 'hours' => 7, 'minutes' => 0]],
            'minRecoatingInterval' => [['temperature_at' => 20, 'days' => 0, 'hours' => 12, 'minutes' => 0]],
        ];
    }

    /** @param array{minRecoatingInterval?: list<DryingTimePointDTO>, maxRecoatingInterval?: ?list<DryingTimePointDTO>} $override */
    private function mapperDto(array $override): CoatingDTO
    {
        $dto = new CoatingDTO();
        $dto->id = '11111111-1111-4111-8111-111111111111';
        $dto->title = 'T';
        $dto->description = 'D';
        $dto->volumeSolid = 60;
        $dto->massDensity = 1.3;
        $dto->base = 'EP';
        $dto->applicationMinTemp = 5;
        $dto->pack = 20;
        $dto->thinner = null;
        $dto->dryToTouch = [$this->point(20, 12)];
        $dto->fullCure = [$this->point(20, 420)];
        $dto->minRecoatingInterval = $override['minRecoatingInterval'] ?? [$this->point(20, 720)];
        $dto->maxRecoatingInterval = $override['maxRecoatingInterval'] ?? null;
        $dto->manufacturer = new ManufacturerDTO();
        $dto->manufacturer->id = '11111111-1111-4111-8111-111111111111';
        $dto->tags = [];
        // dftRange нужен для buildInputDataFromDto, но тест к нему не обращается — оставим необработанным.
        return $dto;
    }

    private function point(int $temperatureAt, int $minutes): DryingTimePointDTO
    {
        $p = new DryingTimePointDTO();
        $p->temperature_at = $temperatureAt;
        $p->time_in_minutes = $minutes;
        $p->is_calculated = false;
        return $p;
    }

    /** @param array<string, mixed> $row */
    private function onlyDhm(array $row): array
    {
        return ['days' => $row['days'], 'hours' => $row['hours'], 'minutes' => $row['minutes']];
    }
}
