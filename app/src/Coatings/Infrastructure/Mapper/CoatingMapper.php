<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DftRangeDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\MixingRatioDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Application\DTO\Coatings\ThermalExposureLimitsDTO;
use App\Coatings\Application\DTO\Colors\ColorDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Application\DTO\Tags\TagDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\Gloss;
use App\Coatings\Domain\Aggregate\Coating\RecoatingInterpolationModel;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\Duration;
use Symfony\Component\Validator\Constraints as Assert;

class CoatingMapper
{
    /**
     * Раскладывает DTO в плоский набор для формы.
     *
     * @return array<string, mixed>
     */
    public function buildInputDataFromDto(CoatingDTO $coatingDTO): array
    {
        $manufacturerId = $coatingDTO->manufacturer->id;
        $coatingTagIds = array_map(
            fn (TagDTO $coatingTag) => $coatingTag->id,
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
        $vars['fullCure'] = $this->decomposeSeriesForForm($vars['fullCure'] ?? null);
        $vars['minRecoatingInterval'] = $this->decomposeTreeDtoForForm($vars['minRecoatingInterval'] ?? null);
        $vars['maxRecoatingInterval'] = $this->decomposeTreeDtoForForm($vars['maxRecoatingInterval'] ?? null);

        $vars['dryHeatExposure'] = $this->decomposeExposureForForm($coatingDTO->dryHeatExposure);
        $vars['immersionExposure'] = $this->decomposeExposureForForm($coatingDTO->immersionExposure);
        $vars['mixingRatio'] = $this->decomposeMixingRatioForForm($coatingDTO->mixingRatio);

        // Возможные цвета — полными записями (id/name/ral/hex), чтобы форма рисовала чипы со свотчами
        // без отдельного гидратора. gloss/isTintable уже скаляры в $vars.
        $vars['colors'] = array_map(
            static fn (ColorDTO $color) => ['id' => $color->id, 'name' => $color->name, 'ral' => $color->ral, 'hex' => $color->hex],
            $coatingDTO->possibleColors,
        );
        unset($vars['possibleColors']);

        return array_merge($vars, compact('manufacturerId', 'coatingTagIds'));
    }

    /**
     * Собирает DTO из плоских данных формы.
     *
     * @param array<string, mixed> $inputData
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

        $dftRange = new DftRangeDTO();
        $dftRange->min = (int) $inputData['minDft'];
        $dftRange->max = (int) $inputData['maxDft'];
        $dftRange->tds_dft = (int) $inputData['tdsDft'];
        $dftRange->type = ThicknessType::MIC->value;
        $dto->dftRange = $dftRange;
        $dto->applicationMinTemp = (int) $inputData['applicationMinTemp'];
        $dto->dryingMaxTemp = isset($inputData['dryingMaxTemp']) && '' !== $inputData['dryingMaxTemp']
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
        $dto->isZincRich = (bool) ($inputData['isZincRich'] ?? false);
        $dto->recoatingInterpolationModel = RecoatingInterpolationModel::tryFrom(
            (string) ($inputData['recoatingInterpolationModel'] ?? '')
        ) ?? RecoatingInterpolationModel::LINEAR;

        $dto->dryHeatExposure = $this->buildExposureFromInput($inputData['dryHeatExposure'] ?? []);
        $dto->immersionExposure = $this->buildExposureFromInput($inputData['immersionExposure'] ?? []);
        $dto->mixingRatio = $this->buildMixingRatioFromInput($inputData['mixingRatio'] ?? []);

        $tags = [];
        foreach ($inputData['tags'] ?? [] as $tag) {
            $coatingTagDto = new TagDTO();
            $coatingTagDto->id = $tag['id'];
            $tags[] = $coatingTagDto;
        }
        $dto->tags = $tags;

        $colors = [];
        foreach ($inputData['colors'] ?? [] as $color) {
            $colorDto = new ColorDTO();
            $colorDto->id = $color['id'];
            $colorDto->name = (string) ($color['name'] ?? '');
            $colorDto->ral = '' !== ($color['ral'] ?? '') ? $color['ral'] : null;
            $colorDto->hex = (string) ($color['hex'] ?? '');
            $colors[] = $colorDto;
        }
        $dto->possibleColors = $colors;
        $dto->gloss = '' !== ($inputData['gloss'] ?? '') ? $inputData['gloss'] : null;
        $dto->isTintable = (bool) ($inputData['isTintable'] ?? false);

        return $dto;
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function parseDurationInput(array $raw): int
    {
        return Duration::fromParts(
            (int) ($raw['days'] ?? 0),
            (int) ($raw['hours'] ?? 0),
            (int) ($raw['minutes'] ?? 0),
        )->minutes();
    }

    /** @return array{days: int, hours: int, minutes: int} */
    public function decomposeDurationForForm(int $totalMinutes): array
    {
        return Duration::ofMinutes($totalMinutes)->toParts();
    }

    public function getValidationCollectionCoating(): Assert\Collection
    {
        return new Assert\Collection(fields: [
            'title' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length(min: 3, max: 100, maxMessage: 'Название не должно быть длиннее {{ limit }}.', minMessage: 'Название не должно быть короче {{ limit }}.'),
            ],
            'description' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length(min: 3, max: 1500, maxMessage: 'Описание не должно быть длиннее {{ limit }}.', minMessage: 'Описание не должно быть короче {{ limit }}.'),
            ],
            'volumeSolid' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(min: 10, max: 100, notInRangeMessage: 'Сухой остаток должен быть от {{ min }} до {{ max }}.'),
            ],
            'massDensity' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(min: 0, max: 100, notInRangeMessage: 'Плотность должна быть от {{ min }} до {{ max }}.'),
            ],
            'tdsDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(min: 10, max: 9999, notInRangeMessage: 'ТСП тех карты должна быть от {{ min }} до {{ max }}.'),
            ],
            'minDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(min: 10, max: 9999, notInRangeMessage: 'Мин ТСП должна быть от {{ min }} до {{ max }}.'),
            ],
            'maxDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(min: 10, max: 9999, notInRangeMessage: 'Макс ТСП должна быть от {{ min }} до {{ max }}.'),
            ],
            'applicationMinTemp' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(min: -30, max: 50, notInRangeMessage: 'Мин Т нанесения должна быть от {{ min }} до {{ max }}.'),
            ],
            'dryingMaxTemp' => new Assert\Optional([
                new Assert\Type('numeric'),
                new Assert\Range(min: 0, max: 250, notInRangeMessage: 'Макс Т сушки должна быть от {{ min }} до {{ max }}.'),
            ]),
            'dryToTouch' => $this->seriesFieldConstraints(required: true),
            'fullCure' => $this->seriesFieldConstraints(required: true),
            // min обязателен на структурном уровне; content-валидация (хотя бы одна точка > 0)
            // живёт в домене (TimeAtTemperature) и долетает до пользователя через AppException → banner.
            'minRecoatingInterval' => $this->recoatingNodeConstraints(required: true),
            'maxRecoatingInterval' => $this->recoatingNodeConstraints(required: false),
            'pack' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range(min: 1, max: 1000, notInRangeMessage: 'Упаковка должна быть от {{ min }} до {{ max }}.'),
            ],
            'manufacturer' => new Assert\Collection(fields: [
                'id' => [new Assert\NotBlank(), new Assert\Uuid()],
                'title' => new Assert\Optional(new Assert\Type('string')),
                'description' => new Assert\Optional(new Assert\Type('string')),
            ]),
            'tags' => new Assert\Optional([
                new Assert\All([new Assert\Collection(fields: [
                    'id' => [new Assert\NotBlank(), new Assert\Uuid()],
                    'title' => new Assert\Optional(new Assert\Type('string')),
                    'type' => new Assert\Optional(new Assert\Type('string')),
                ])]),
            ]),
            // Возможные цвета: структурно — валидный id + name/hex (форма шлёт полные данные).
            // Инвариант «не-колеруемое ⇒ ≥1 цвет» — в домене (Coating::applyColorScheme).
            'colors' => new Assert\Optional([
                new Assert\All([new Assert\Collection(fields: [
                    'id' => [new Assert\NotBlank(), new Assert\Uuid()],
                    'name' => [new Assert\NotBlank(), new Assert\Type('string')],
                    'ral' => new Assert\Optional([new Assert\Type('string')]),
                    'hex' => [new Assert\NotBlank(), new Assert\Type('string')],
                ])]),
            ]),
            'gloss' => new Assert\Optional([
                new Assert\Choice(choices: array_map(static fn (Gloss $g) => $g->value, Gloss::cases()), message: 'Недопустимая степень блеска.'),
            ]),
            // HTML-чекбокс: строка "on"/отсутствует; в bool кастит buildCoatingDtoFromInputData.
            'isTintable' => new Assert\Optional([new Assert\Type('string')]),
            // HTML-чекбокс шлёт строку "on" при отметке и не шлётся вовсе при снятой.
            // Структурно это опциональная строка; в bool её кастит buildCoatingDtoFromInputData.
            'isZincRich' => new Assert\Optional([new Assert\Type('string')]),
            'recoatingInterpolationModel' => new Assert\Optional([
                new Assert\Choice(choices: array_map(
                    static fn (RecoatingInterpolationModel $m) => $m->value,
                    RecoatingInterpolationModel::cases(),
                ), message: 'Недопустимая модель интерполяции.'),
            ]),
            // Структурно: каждое поле секции — пусто или целое число, с человеческим сообщением
            // и явной меткой секции. Инварианты (min<max, peak>max, duration>0) — домен; решение
            // «все пусто → пределов нет» — ThermalExposureLimitsBuilder.
            'dryHeatExposure' => $this->exposureFieldConstraints('Сухое тепло'),
            'immersionExposure' => $this->exposureFieldConstraints('Погружение'),
            // Только структура; ≥2 компонента / >0 / ≤2 знака / ≥1 база / равное число при
            // обеих базах — инварианты домена (MixingRatio/PartsRatio/PositiveNumber → AppException).
            'mixingRatio' => new Assert\Optional([new Assert\Type('array')]),
        ], allowExtraFields: true);
    }

    /**
     * Структурная валидация секции температурных пределов: каждое поле — пусто или целое число,
     * человеческое сообщение с явной меткой секции. Инварианты (min<max, peak>max, duration>0) —
     * в ThermalExposureLimits; решение «все пусто → пределов нет» — в ThermalExposureLimitsBuilder.
     */
    private function exposureFieldConstraints(string $sectionLabel): Assert\Optional
    {
        $integerOrBlank = fn (string $noun): Assert\Optional => new Assert\Optional([
            new Assert\Regex(pattern: '/^(-?\d+)?$/', message: sprintf('Секция «%s»: %s должна быть целым числом.', $sectionLabel, $noun)),
        ]);

        return new Assert\Optional([
            new Assert\Collection(fields: [
                'continuous_min' => $integerOrBlank('минимальная температура'),
                'continuous_max' => $integerOrBlank('максимальная температура'),
                'peak_max' => $integerOrBlank('пиковая температура'),
                'peak_duration_minutes' => new Assert\Optional([
                    new Assert\Regex(pattern: '/^(-?\d+)?$/', message: sprintf('Секция «%s»: длительность пика должна быть целым числом минут.', $sectionLabel)),
                ]),
            ], allowExtraFields: true),
        ]);
    }

    /**
     * Pure shape: 4 плоских поля секции → DTO (пусто → null, иначе int). Ничего не решает и не
     * валидирует: «целое число» проверяет Assert (exposureFieldConstraints), «все пусто → пределов
     * нет (null)» решает ThermalExposureLimitsBuilder, инварианты — доменный VO.
     *
     * @param array<string, mixed> $raw
     */
    private function buildExposureFromInput(array $raw): ThermalExposureLimitsDTO
    {
        $dto = new ThermalExposureLimitsDTO();
        $dto->continuous_min = $this->intOrNull($raw['continuous_min'] ?? '');
        $dto->continuous_max = $this->intOrNull($raw['continuous_max'] ?? '');
        $dto->peak_max = $this->intOrNull($raw['peak_max'] ?? '');
        $dto->peak_duration_minutes = $this->intOrNull($raw['peak_duration_minutes'] ?? '');

        return $dto;
    }

    private function intOrNull(mixed $value): ?int
    {
        $trimmed = is_string($value) ? trim($value) : (string) $value;

        return '' === $trimmed ? null : (int) $trimmed;
    }

    /**
     * Раскладывает ThermalExposureLimitsDTO в плоский набор для формы (или пустой массив).
     *
     * @return array<string, mixed>
     */
    private function decomposeExposureForForm(?ThermalExposureLimitsDTO $dto): array
    {
        if (null === $dto) {
            return [
                'continuous_min' => '',
                'continuous_max' => '',
                'peak_max' => '',
                'peak_duration_minutes' => '',
            ];
        }

        return [
            'continuous_min' => $dto->continuous_min,
            'continuous_max' => $dto->continuous_max,
            'peak_max' => $dto->peak_max ?? '',
            'peak_duration_minutes' => $dto->peak_duration_minutes ?? '',
        ];
    }

    /**
     * Пере-shape формы (mixingRatio[volume][], mixingRatio[mass][]) в DTO: ячейки к float,
     * пустые отброшены (форм-нормализация). НИЧЕГО не решает: «пусто → нет соотношения» и
     * сборку VO делает хендлер, инварианты — домен.
     *
     * @param array<string, mixed> $raw
     */
    private function buildMixingRatioFromInput(array $raw): MixingRatioDTO
    {
        $dto = new MixingRatioDTO();
        $dto->volume = $this->cleanParts($raw['volume'] ?? []);
        $dto->mass = $this->cleanParts($raw['mass'] ?? []);

        return $dto;
    }

    /**
     * Ячейки базы в list<float>: пустые отброшены (иначе PositiveNumber((float)'')=0 →
     * AppException), ключи переуплотнены (после удаления строки индексы разрежены).
     *
     * @return list<float>
     */
    private function cleanParts(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $filled = array_filter(
            $raw,
            static fn ($v) => is_string($v) ? '' !== trim($v) : null !== $v,
        );

        return array_values(array_map(static fn ($v) => (float) $v, $filled));
    }

    /**
     * Раскладывает MixingRatioDTO в shape формы (совпадает с POST — чтобы после ошибки
     * секция восстановилась из сырого payload).
     *
     * @return array{volume: list<float>, mass: list<float>}
     */
    private function decomposeMixingRatioForForm(?MixingRatioDTO $dto): array
    {
        if (null === $dto) {
            return ['volume' => [], 'mass' => []];
        }

        return ['volume' => $dto->volume ?? [], 'mass' => $dto->mass ?? []];
    }

    /**
     * Рекурсивно строит RecoatingIntervalTreeDTO из nested-array формы.
     * Чистый shape→DTO маппинг без бизнес-фильтрации (валидация — в домене через RecoatingTreeBuilder).
     */
    /**
     * @param array<string, mixed> $raw
     */
    private function buildTreeDtoFromInput(array $raw): RecoatingIntervalTreeDTO
    {
        $node = new RecoatingIntervalTreeDTO();
        $node->default = $this->buildPointsFromInput($raw['default']['points'] ?? []);
        foreach ($raw['branches'] ?? [] as $key => $childRaw) {
            if (!is_string($key) || '' === $key) {
                continue;
            }
            $node->branches[$key] = $this->buildTreeDtoFromInput((array) $childRaw);
        }

        return $node;
    }

    /** Узел считается пустым, если у него нет default-точек и нет (рекурсивно) непустых веток. */
    private function isTreeDtoEffectivelyEmpty(RecoatingIntervalTreeDTO $node): bool
    {
        if ([] !== $node->default) {
            return false;
        }
        foreach ($node->branches as $child) {
            if (!$this->isTreeDtoEffectivelyEmpty($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Декомпозит RecoatingIntervalTreeDTO в nested-array для шаблона. NULL → пустой узел.
     *
     * @return array<string, mixed>
     */
    private function decomposeTreeDtoForForm(?RecoatingIntervalTreeDTO $node): array
    {
        if (null === $node) {
            return ['default' => ['points' => []], 'branches' => []];
        }
        $branches = [];
        foreach ($node->branches as $key => $child) {
            $branches[$key] = $this->decomposeTreeDtoForForm($child);
        }

        return [
            'default' => ['points' => $this->decomposeSeriesForForm($node->default)],
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
        $nodeShape = new Assert\Collection(fields: [
            'default' => new Assert\Optional([
                new Assert\Collection(fields: [
                    'points' => new Assert\Optional($this->pointsListConstraint()),
                ], allowExtraFields: true),
            ]),
            'branches' => new Assert\Optional([new Assert\Type('array')]),
        ], allowExtraFields: true);

        return $required ? $nodeShape : new Assert\Optional([$nodeShape]);
    }

    private function pointsListConstraint(): Assert\All
    {
        return new Assert\All([
            new Assert\Collection(fields: [
                'temperature_at' => [new Assert\NotBlank(), new Assert\Type('numeric')],
                'days' => new Assert\Optional([new Assert\Type('numeric')]),
                'hours' => new Assert\Optional([new Assert\Type('numeric')]),
                'minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                'time_in_minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                'is_calculated' => new Assert\Optional([new Assert\Type('numeric')]),
                'kind' => new Assert\Optional([new Assert\Choice(choices: ['duration', 'unlimited', 'unknown'])]),
            ], allowExtraFields: true),
        ]);
    }

    /**
     * @param ?list<DryingTimePointDTO> $points null = весь max-tree отсутствует (старая семантика)
     *
     * @return list<array<string, mixed>>
     */
    private function decomposeSeriesForForm(?array $points): array
    {
        if (null === $points) {
            return [];
        }

        return array_map(
            fn (DryingTimePointDTO $p) => array_merge(
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
        if (null === $minutes) {
            return 'unknown';
        }
        if (0 === $minutes) {
            return 'unlimited';
        }

        return 'duration';
    }

    /**
     * @param list<array<string, mixed>> $rawPoints
     *
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
    /**
     * @param array<string, mixed> $raw
     */
    private function resolveTimeInMinutes(array $raw): ?int
    {
        $kind = $raw['kind'] ?? null;

        if ('unlimited' === $kind) {
            return 0;
        }
        if ('unknown' === $kind) {
            return null;
        }

        // duration (явный или legacy)
        if (isset($raw['time_in_minutes']) && '' !== $raw['time_in_minutes']) {
            $value = (int) $raw['time_in_minutes'];

            return 0 === $value ? null : $value;
        }
        $value = $this->parseDurationInput($raw);

        return 0 === $value ? null : $value;
    }

    /**
     * Валидация одной температурно-зависимой серии.
     * required=true — поле обязательно (NotBlank); required=false — допускается пустой массив (нет точек).
     */
    /**
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    private function seriesFieldConstraints(bool $required): array
    {
        $rowConstraint = new Assert\All([
            new Assert\Collection(fields: [
                'temperature_at' => [new Assert\NotBlank(), new Assert\Type('numeric')],
                'days' => new Assert\Optional([new Assert\Type('numeric')]),
                'hours' => new Assert\Optional([new Assert\Type('numeric')]),
                'minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                'time_in_minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                'is_calculated' => new Assert\Optional(new Assert\Type('numeric')),
                'kind' => new Assert\Optional([new Assert\Choice(choices: ['duration', 'unlimited', 'unknown'])]),
            ], allowExtraFields: true),
        ]);

        return $required
            ? [new Assert\NotBlank(), $rowConstraint]
            : [new Assert\Optional($rowConstraint)];
    }
}
