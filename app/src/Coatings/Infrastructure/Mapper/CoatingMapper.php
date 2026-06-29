<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DftRangeDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use Symfony\Component\Validator\Constraints as Assert;

class CoatingMapper
{
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

        $vars['dryToTouch'] = $this->decomposeSeriesForForm($vars['dryToTouch'] ?? null);
        $vars['fullCure']   = $this->decomposeSeriesForForm($vars['fullCure'] ?? null);
        $vars['minRecoatingInterval'] = $this->decomposeTreeDtoForForm($vars['minRecoatingInterval'] ?? null);
        $vars['maxRecoatingInterval'] = $this->decomposeTreeDtoForForm($vars['maxRecoatingInterval'] ?? null);

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
        $dto->dryingMaxTemp = isset($inputData['dryingMaxTemp']) && $inputData['dryingMaxTemp'] !== ''
            ? (int) $inputData['dryingMaxTemp']
            : 50;

        $dto->dryToTouch = $this->buildPointsFromInput($inputData['dryToTouch'] ?? []);
        $dto->fullCure = $this->buildPointsFromInput($inputData['fullCure'] ?? []);
        // min: точки валидируются в RecoatingTreeBuilder::buildMinTree — каждая обязана иметь duration > 0, иначе AppException.
        $dto->minRecoatingInterval = $this->buildTreeDtoFromInput($inputData['minRecoatingInterval'] ?? []);
        // max: точки несут kind = duration/unlimited/unknown. Mapper передаёт всё в домен без фильтрации;
        // domain различает три состояния через ?int $timeInMinutes.
        $maxNode = $this->buildTreeDtoFromInput($inputData['maxRecoatingInterval'] ?? []);
        $dto->maxRecoatingInterval = $this->isTreeDtoEffectivelyEmpty($maxNode) ? null : $maxNode;

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
            'dryingMaxTemp' => new Assert\Optional([
                new Assert\Type('numeric'),
                new Assert\Range(['min' => 0, 'max' => 250, 'notInRangeMessage' => 'Макс Т сушки должна быть от {{ min }} до {{ max }}.']),
            ]),
            'dryToTouch'           => $this->seriesFieldConstraints(required: true),
            'fullCure'             => $this->seriesFieldConstraints(required: true),
            // min обязателен на структурном уровне; content-валидация (хотя бы одна точка > 0)
            // живёт в домене (TimeAtTemperature) и долетает до пользователя через AppException → banner.
            'minRecoatingInterval' => $this->recoatingNodeConstraints(required: true),
            'maxRecoatingInterval' => $this->recoatingNodeConstraints(required: false),
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
     * Рекурсивно строит RecoatingIntervalTreeDTO из nested-array формы.
     * Чистый shape→DTO маппинг без бизнес-фильтрации (валидация — в домене через RecoatingTreeBuilder).
     */
    private function buildTreeDtoFromInput(array $raw): RecoatingIntervalTreeDTO
    {
        $node = new RecoatingIntervalTreeDTO();
        $node->default = $this->buildPointsFromInput($raw['default']['points'] ?? []);
        foreach ($raw['branches'] ?? [] as $key => $childRaw) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $node->branches[$key] = $this->buildTreeDtoFromInput((array) $childRaw);
        }
        return $node;
    }

    /** Узел считается пустым, если у него нет default-точек и нет (рекурсивно) непустых веток. */
    private function isTreeDtoEffectivelyEmpty(RecoatingIntervalTreeDTO $node): bool
    {
        if ($node->default !== []) {
            return false;
        }
        foreach ($node->branches as $child) {
            if (!$this->isTreeDtoEffectivelyEmpty($child)) {
                return false;
            }
        }
        return true;
    }

    /** Декомпозит RecoatingIntervalTreeDTO в nested-array для шаблона. NULL → пустой узел. */
    private function decomposeTreeDtoForForm(?RecoatingIntervalTreeDTO $node): array
    {
        if ($node === null) {
            return ['default' => ['points' => []], 'branches' => []];
        }
        $branches = [];
        foreach ($node->branches as $key => $child) {
            $branches[$key] = $this->decomposeTreeDtoForForm($child);
        }
        return [
            'default'  => ['points' => $this->decomposeSeriesForForm($node->default)],
            'branches' => $branches,
        ];
    }

    /**
     * Структурная валидация одного узла дерева recoating-интервалов.
     * Допускает рекурсивную форму `{default:{points:[...]}, branches:{<key>: <same>}}`.
     * Проверка ключей сред/оснований и физических правил — на уровне домена.
     */
    private function recoatingNodeConstraints(bool $required): Assert\Collection|Assert\Optional
    {
        $nodeShape = new Assert\Collection([
            'fields' => [
                'default' => new Assert\Optional([
                    new Assert\Collection([
                        'fields' => [
                            'points' => new Assert\Optional($this->pointsListConstraint()),
                        ],
                        'allowExtraFields' => true,
                    ]),
                ]),
                'branches' => new Assert\Optional([new Assert\Type('array')]),
            ],
            'allowExtraFields' => true,
        ]);
        return $required ? $nodeShape : new Assert\Optional([$nodeShape]);
    }

    private function pointsListConstraint(): Assert\All
    {
        return new Assert\All([
            new Assert\Collection([
                'fields' => [
                    'temperature_at'  => [new Assert\NotBlank(), new Assert\Type('numeric')],
                    'days'            => new Assert\Optional([new Assert\Type('numeric')]),
                    'hours'           => new Assert\Optional([new Assert\Type('numeric')]),
                    'minutes'         => new Assert\Optional([new Assert\Type('numeric')]),
                    'time_in_minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                    'is_calculated'   => new Assert\Optional([new Assert\Type('numeric')]),
                    'kind'            => new Assert\Optional([new Assert\Choice(['duration', 'unlimited', 'unknown'])]),
                ],
                'allowExtraFields' => true,
            ]),
        ]);
    }

    /**
     * @param ?list<DryingTimePointDTO> $points null = весь max-tree отсутствует (старая семантика).
     * @return list<array<string, mixed>>
     */
    private function decomposeSeriesForForm(?array $points): array
    {
        if ($points === null) {
            return [];
        }
        return array_map(
            fn(DryingTimePointDTO $p) => array_merge(
                $this->decomposeDurationForForm($p->time_in_minutes ?? 0),
                [
                    'temperature_at' => $p->temperature_at,
                    'time_in_minutes' => $p->time_in_minutes,
                    'is_calculated' => $p->is_calculated,
                    'kind' => $this->kindForMinutes($p->time_in_minutes),
                ],
            ),
            $points,
        );
    }

    private function kindForMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return 'unknown';
        }
        if ($minutes === 0) {
            return 'unlimited';
        }
        return 'duration';
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
            $point->time_in_minutes = $this->resolveTimeInMinutes($raw);
            $point->is_calculated = (bool) ($raw['is_calculated'] ?? false);
            return $point;
        }, $rawPoints));
    }

    /**
     * Резолвит time_in_minutes из формы с учётом kind:
     *  - kind = 'duration' → парсим days/hours/minutes; 0 → null (юзер не ввёл).
     *  - kind = 'unlimited' → 0.
     *  - kind = 'unknown' → null.
     *  - kind отсутствует (legacy / старый формат): парсим как duration; 0 → null.
     */
    private function resolveTimeInMinutes(array $raw): ?int
    {
        $kind = $raw['kind'] ?? null;

        if ($kind === 'unlimited') {
            return 0;
        }
        if ($kind === 'unknown') {
            return null;
        }

        // duration (явный или legacy)
        if (isset($raw['time_in_minutes']) && $raw['time_in_minutes'] !== '') {
            $value = (int) $raw['time_in_minutes'];
            return $value === 0 ? null : $value;
        }
        $value = $this->parseDurationInput($raw);
        return $value === 0 ? null : $value;
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
                    'kind'            => new Assert\Optional([new Assert\Choice(['duration', 'unlimited', 'unknown'])]),
                ],
                'allowExtraFields' => true,
            ]),
        ]);

        return $required
            ? [new Assert\NotBlank(), $rowConstraint]
            : [new Assert\Optional($rowConstraint)];
    }
}
