<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Api;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;
use App\Coatings\Application\DTO\Coatings\MixingRatioDTO;
use App\Coatings\Infrastructure\Api\CoatingSuggestNormalizer;
use PHPUnit\Framework\TestCase;

final class CoatingSuggestNormalizerTest extends TestCase
{
    public function test_maps_two_component_with_mixing_ratio(): void
    {
        $ratio = new MixingRatioDTO();
        $ratio->volume = [3.0, 1.0];
        $ratio->mass = [100.0, 23.0];

        $dto = $this->dto('id-1', 'Coating (EP, 80–150 мкм)', 'EP', 80, 150, 60, 20.0, 1.5, $ratio);

        self::assertSame([
            'id' => 'id-1',
            'title' => 'Coating (EP, 80–150 мкм)',
            'base' => 'EP',
            'dftMin' => 80,
            'dftMax' => 150,
            'volumeSolid' => 60,
            'pack' => 20.0,
            'massDensity' => 1.5,
            'mixingRatio' => ['volume' => [3.0, 1.0], 'mass' => [100.0, 23.0]],
        ], CoatingSuggestNormalizer::toArray($dto));
    }

    public function test_maps_single_component_null_ratio(): void
    {
        $dto = $this->dto('id-2', 'Mono (AK, 40–80 мкм)', 'AK', 40, 80, 45, 10.0, 1.2, null);

        $arr = CoatingSuggestNormalizer::toArray($dto);

        self::assertNull($arr['mixingRatio']);
        self::assertSame('id-2', $arr['id']);
        self::assertSame('AK', $arr['base']);
    }

    private function dto(
        string $id,
        string $title,
        string $base,
        int $dftMin,
        int $dftMax,
        int $volumeSolid,
        float $pack,
        float $massDensity,
        ?MixingRatioDTO $ratio,
    ): CoatingSuggestDTO {
        $dto = new CoatingSuggestDTO();
        $dto->id = $id;
        $dto->title = $title;
        $dto->base = $base;
        $dto->dftMin = $dftMin;
        $dto->dftMax = $dftMax;
        $dto->volumeSolid = $volumeSolid;
        $dto->pack = $pack;
        $dto->massDensity = $massDensity;
        $dto->mixingRatio = $ratio;

        return $dto;
    }
}
