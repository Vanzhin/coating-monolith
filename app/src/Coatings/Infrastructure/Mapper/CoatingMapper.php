<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DftRangeDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use Symfony\Component\Validator\Constraints as Assert;

class CoatingMapper
{
    /** Имена всех температурно-зависимых полей-серий, которые форма редактирует одинаково. */
    private const TEMPERATURE_SERIES_FIELDS = [
        'dryToTouch',
        'fullCure',
        'minRecoatingInterval',
        'maxRecoatingInterval',
    ];

    /** Раскладывает DTO в плоский набор для формы. */
    public function buildInputDataFromDto(CoatingDTO $coatingDTO): array
    {
        $manufacturerId = $coatingDTO->manufacturer->id;
        $coatingTagIds = array_map(
            fn(CoatingTagDTO $coatingTag) => $coatingTag->id,
            $coatingDTO->tags,
        );

        $vars = get_object_vars($coatingDTO);

        if (isset($vars['dftRange']) && $vars['dftRange'] instanceof DftRangeDTO) {
            $vars['minDft'] = $vars['dftRange']->min;
            $vars['maxDft'] = $vars['dftRange']->max;
            $vars['tdsDft'] = $vars['dftRange']->tds_dft;
            unset($vars['dftRange']);
        }

        foreach (self::TEMPERATURE_SERIES_FIELDS as $field) {
            $vars[$field] = $this->decomposeSeriesForForm($vars[$field] ?? null);
        }

        return array_merge($vars, compact('manufacturerId', 'coatingTagIds'));
    }

    /** Собирает DTO из плоских данных формы. */
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

        $dftRange = new DftRangeDTO();
        $dftRange->min = (int) ($inputData['minDft']);
        $dftRange->max = (int) ($inputData['maxDft']);
        $dftRange->tds_dft = (int) ($inputData['tdsDft']);
        $dftRange->type = ThicknessType::MIC->value;
        $dto->dftRange = $dftRange;
        $dto->applicationMinTemp = (int) $inputData['applicationMinTemp'];

        $dto->dryToTouch = $this->buildPointsFromInput($inputData['dryToTouch'] ?? []);
        $dto->fullCure = $this->buildPointsFromInput($inputData['fullCure'] ?? []);
        $dto->minRecoatingInterval = $this->buildPointsFromInput($inputData['minRecoatingInterval'] ?? []);
        // max — необязателен. В комбинированном UI строки max идут параллельно min,
        // но пустые (все длительности = 0) трактуются как «нет точки max при этой температуре».
        // Если после отбрасывания пустых не осталось ни одной точки — серия max = null
        // («без верхней границы»).
        $maxRowsFilled = array_values(array_filter(
            $inputData['maxRecoatingInterval'] ?? [],
            fn(array $row) => $this->parseDurationInput($row) > 0,
        ));
        $dto->maxRecoatingInterval = $maxRowsFilled === [] ? null : $this->buildPointsFromInput($maxRowsFilled);

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

    public function parseDurationInput(array $raw): int
    {
        $days    = (int) ($raw['days']    ?? 0);
        $hours   = (int) ($raw['hours']   ?? 0);
        $minutes = (int) ($raw['minutes'] ?? 0);
        return $days * 24 * 60 + $hours * 60 + $minutes;
    }

    /** @return array{days: int, hours: int, minutes: int} */
    public function decomposeDurationForForm(int $totalMinutes): array
    {
        $days = intdiv($totalMinutes, 24 * 60);
        $rem = $totalMinutes - $days * 24 * 60;
        $hours = intdiv($rem, 60);
        $minutes = $rem - $hours * 60;
        return ['days' => $days, 'hours' => $hours, 'minutes' => $minutes];
    }

    public function getValidationCollectionCoating(): Assert\Collection
    {
        return new Assert\Collection([
            'title' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3, 'max' => 100,
                    'maxMessage' => 'Название не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Название не должно быть короче {{ limit }}.',
                ]),
            ],
            'description' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3, 'max' => 1500,
                    'maxMessage' => 'Описание не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Описание не должно быть короче {{ limit }}.',
                ]),
            ],
            'volumeSolid' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(['min' => 10, 'max' => 100, 'notInRangeMessage' => 'Сухой остаток должен быть от {{ min }} до {{ max }}.']),
            ],
            'massDensity' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(['min' => 0, 'max' => 100, 'notInRangeMessage' => 'Плотность должна быть от {{ min }} до {{ max }}.']),
            ],
            'tdsDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(['min' => 10, 'max' => 9999, 'notInRangeMessage' => 'ТСП тех карты должна быть от {{ min }} до {{ max }}.']),
            ],
            'minDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(['min' => 10, 'max' => 9999, 'notInRangeMessage' => 'Мин ТСП должна быть от {{ min }} до {{ max }}.']),
            ],
            'maxDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(['min' => 10, 'max' => 9999, 'notInRangeMessage' => 'Макс ТСП должна быть от {{ min }} до {{ max }}.']),
            ],
            'applicationMinTemp' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(['min' => -30, 'max' => 50, 'notInRangeMessage' => 'Мин Т нанесения должна быть от {{ min }} до {{ max }}.']),
            ],
            'dryToTouch'           => $this->seriesFieldConstraints(required: true),
            'fullCure'             => $this->seriesFieldConstraints(required: true),
            'minRecoatingInterval' => $this->seriesFieldConstraints(required: true),
            'maxRecoatingInterval' => $this->seriesFieldConstraints(required: false),
            'pack' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(['min' => 1, 'max' => 1000, 'notInRangeMessage' => 'Упаковка должна быть от {{ min }} до {{ max }}.']),
            ],
            'manufacturer' => new Assert\Collection([
                'id' => [new Assert\NotBlank(), new Assert\Uuid()],
                'title' => new Assert\Optional(new Assert\Type('string')),
                'description' => new Assert\Optional(new Assert\Type('string')),
            ]),
            'tags' => new Assert\Optional([
                new Assert\All(new Assert\Collection([
                    'id' => [new Assert\NotBlank(), new Assert\Uuid()],
                    'title' => new Assert\Optional(new Assert\Type('string')),
                    'type' => new Assert\Optional(new Assert\Type('string')),
                ])),
            ]),
        ], allowExtraFields: true);
    }

    /**
     * @param ?list<DryingTimePointDTO> $points null = «без верхней границы» (только для max-recoat)
     * @return list<array<string, mixed>>
     */
    private function decomposeSeriesForForm(?array $points): array
    {
        if ($points === null) {
            return [];
        }
        return array_map(
            fn(DryingTimePointDTO $p) => array_merge(
                $this->decomposeDurationForForm($p->time_in_minutes),
                [
                    'temperature_at' => $p->temperature_at,
                    'time_in_minutes' => $p->time_in_minutes,
                    'is_calculated' => $p->is_calculated,
                ],
            ),
            $points,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawPoints
     * @return list<DryingTimePointDTO>
     */
    private function buildPointsFromInput(array $rawPoints): array
    {
        return array_values(array_map(function (array $raw): DryingTimePointDTO {
            $point = new DryingTimePointDTO();
            $point->temperature_at = (int) ($raw['temperature_at'] ?? 20);
            // Поддерживаем оба формата: новый {days, hours, minutes} и старый {time_in_minutes}.
            $point->time_in_minutes = isset($raw['time_in_minutes'])
                ? (int) $raw['time_in_minutes']
                : $this->parseDurationInput($raw);
            $point->is_calculated = (bool) ($raw['is_calculated'] ?? false);
            return $point;
        }, $rawPoints));
    }

    /**
     * Валидация одной температурно-зависимой серии.
     * required=true — поле обязательно (NotBlank); required=false — допускается пустой массив (нет точек).
     */
    private function seriesFieldConstraints(bool $required): array
    {
        $rowConstraint = new Assert\All([
            new Assert\Collection([
                'fields' => [
                    'temperature_at'  => [new Assert\NotBlank(), new Assert\Type('numeric')],
                    'days'            => new Assert\Optional([new Assert\Type('numeric')]),
                    'hours'           => new Assert\Optional([new Assert\Type('numeric')]),
                    'minutes'         => new Assert\Optional([new Assert\Type('numeric')]),
                    'time_in_minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                    'is_calculated'   => new Assert\Optional(new Assert\Type('numeric')),
                ],
                'allowExtraFields' => true,
            ]),
        ]);

        return $required
            ? [new Assert\NotBlank(), $rowConstraint]
            : [new Assert\Optional($rowConstraint)];
    }
}
