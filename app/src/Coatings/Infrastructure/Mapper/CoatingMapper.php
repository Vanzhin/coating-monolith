<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use Symfony\Component\Validator\Constraints as Assert;

class CoatingMapper
{
    /**
     * Раскладывает DTO с VO-форматом обратно в плоский набор скаляров,
     * который ожидают существующие формы (одна точка профиля при +20°C).
     */
    public function buildInputDataFromDto(CoatingDTO $coatingDTO): array
    {
        $manufacturerId = $coatingDTO->manufacturer->id;
        $coatingTagIds = array_map(
            fn(CoatingTagDTO $coatingTag) => $coatingTag->id,
            $coatingDTO->tags,
        );

        $vars = get_object_vars($coatingDTO);

        if (isset($vars['dftRange'])) {
            $vars['minDft'] = $vars['dftRange']['min'];
            $vars['maxDft'] = $vars['dftRange']['max'];
            $vars['tdsDft'] = $vars['dftRange']['tds_dft'];
            unset($vars['dftRange']);
        }

        foreach (['dryToTouch', 'fullCure'] as $key) {
            if (isset($vars[$key]) && is_array($vars[$key])) {
                $firstPoint = $vars[$key][0] ?? null;
                $vars[$key] = $firstPoint !== null ? (float) $firstPoint['time_in_minutes'] : 0.0;
            }
        }

        return array_merge($vars, compact('manufacturerId', 'coatingTagIds'));
    }

    /**
     * Собирает DTO с VO-форматом из плоских данных формы. Профили высыхания
     * формируются как одна точка при +20°C (стандартная температура техкарт).
     */
    public function buildCoatingDtoFromInputData(array $inputData): CoatingDTO
    {
        $manufacturer = new ManufacturerDTO();
        $manufacturer->id = $inputData['manufacturer']['id'];

        $dto = new CoatingDTO();
        if ($inputData['id'] ?? null) {
            $dto->id = $inputData['id'];
        }
        $dto->title = $inputData['title'] ?? null;
        $dto->thinner = isset($inputData['thinner']) && strlen($inputData['thinner']) > 0
            ? $inputData['thinner']
            : null;
        $dto->description = $inputData['description'] ?? null;
        $dto->volumeSolid = (int) $inputData['volumeSolid'];
        $dto->massDensity = (float) $inputData['massDensity'];
        $dto->base = CoatingBase::from($inputData['base'])->value;
        $dto->dftRange = [
            'min' => (int) ($inputData['minDft'] ?? 0),
            'max' => (int) ($inputData['maxDft'] ?? 0),
            'tds_dft' => (int) ($inputData['tdsDft'] ?? 0),
            'type' => ThicknessType::MIC->value,
        ];
        $dto->applicationMinTemp = (int) $inputData['applicationMinTemp'];
        $dto->dryToTouch = $this->buildSinglePointProfile((float) ($inputData['dryToTouch'] ?? 0));
        $dto->minRecoatingInterval = (float) $inputData['minRecoatingInterval'];
        $dto->maxRecoatingInterval = isset($inputData['maxRecoatingInterval']) && $inputData['maxRecoatingInterval'] !== ''
            ? (float) $inputData['maxRecoatingInterval']
            : null;
        $dto->fullCure = $this->buildSinglePointProfile((float) ($inputData['fullCure'] ?? 0));
        $dto->manufacturer = $manufacturer;
        $dto->pack = (float) $inputData['pack'];

        $tags = [];
        foreach ($inputData['tags'] ?? [] as $tag) {
            $coatingTagDto = new CoatingTagDTO();
            $coatingTagDto->id = $tag['id'];
            $tags[] = $coatingTagDto;
        }
        $dto->tags = $tags;

        return $dto;
    }

    /**
     * @return list<array{temperature_at: int, time_in_minutes: float, is_calculated: bool}>
     */
    private function buildSinglePointProfile(float $minutes): array
    {
        return [
            [
                'temperature_at' => 20,
                'time_in_minutes' => $minutes,
                'is_calculated' => false,
            ],
        ];
    }

    public function getValidationCollectionCoating(): Assert\Collection
    {
        return new Assert\Collection([
            'title' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 100,
                    'maxMessage' => 'Название не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Название не должно быть короче {{ limit }}.',
                ]),
            ],
            'description' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 1500,
                    'maxMessage' => 'Описание не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Описание не должно быть короче {{ limit }}.',
                ]),
            ],
            'volumeSolid' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 100,
                    'notInRangeMessage' => 'Сухой остаток должен быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'massDensity' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 100,
                    'notInRangeMessage' => 'Плотность должна быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'tdsDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 9999,
                    'notInRangeMessage' => 'ТСП тех карты должна быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'minDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 9999,
                    'notInRangeMessage' => 'Мин ТСП должна быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'maxDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 9999,
                    'notInRangeMessage' => 'Макс ТСП должна быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'applicationMinTemp' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => -30,
                    'max' => 50,
                    'notInRangeMessage' => 'Мин Т нанесения должна быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'dryToTouch' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 100,
                    'notInRangeMessage' => 'Время "сухой на отлип" должно быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'minRecoatingInterval' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 100,
                    'notInRangeMessage' => 'Мин интервал перекрытия должен быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            // maxRecoatingInterval — опциональное (null = «верхней границы нет»).
            // Доменная проверка >= 0 живёт в Coating::setMaxRecoatingInterval, верхняя граница — HTML5 min/max на форме.
'fullCure' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 1000,
                    'notInRangeMessage' => 'Время полного отверждения должно быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'pack' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 1,
                    'max' => 1000,
                    'notInRangeMessage' => 'Упаковка должна быть в переделах от {{ min }} до {{ max }}.',
                ]),
            ],
            'manufacturer' => new Assert\Collection([
                'id' => [
                    new Assert\NotBlank(),
                    new Assert\Uuid(),
                ],
                'title' => new Assert\Optional(new Assert\Type('string')),
                'description' => new Assert\Optional(new Assert\Type('string')),
            ]),
            'tags' => new Assert\Optional([
                new Assert\All(
                    new Assert\Collection([
                        'id' => [
                            new Assert\NotBlank(),
                            new Assert\Uuid(),
                        ],
                        'title' => new Assert\Optional(new Assert\Type('string')),
                        'type' => new Assert\Optional(new Assert\Type('string')),
                    ]),
                ),
            ]),
        ], allowExtraFields: true);
    }
}
