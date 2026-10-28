<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\SystemSelection;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Inquiries\Application\DTO\ExtractedParameters;
use App\Inquiries\Application\DTO\MappedSystemFilter;
use App\Inquiries\Application\Port\SubstanceResolver;

/**
 * Превращает извлечённые параметры подбора систем в примитивный вход поиска Coatings. Вся mapping-глина
 * тут, не в движке: вещества (имя → id через реестр химстойкости, нерезолв → предупреждение), температуры
 * (рабочая химии → substanceTemperature, тепловая рабочая → thermalTemperature), оси фасета один-в-один.
 */
final readonly class SystemSelectionParameterMapper
{
    /** Сколько систем кладём в снимок ответа; при превышении total подскажет инженеру сузить. */
    private const ANSWER_LIMIT = 50;

    public function __construct(private SubstanceResolver $substances)
    {
    }

    public function map(ExtractedParameters $p): MappedSystemFilter
    {
        $warnings = [];

        $substanceIds = [];
        foreach ($p->chemicalResistance->substances as $name) {
            $id = $this->substances->resolve($name);
            if (null === $id) {
                $warnings[] = sprintf('Вещество «%s» не найдено в реестре — в подборе не учтено.', $name);

                continue;
            }
            $substanceIds[] = $id;
        }

        // Покрытия, заданные заказчиком, — пока нет резолвера имён→id, показываем инженеру отдельно.
        foreach ($p->specifiedCoatings as $coating) {
            $warnings[] = sprintf('Заказчик указал покрытие «%s» — проверьте вручную.', $coating);
        }

        // Фильтр несёт одну тепловую температуру + флаг колонки. Если заданы и рабочая, и пик —
        // фильтруем по пику, рабочая в фильтр не попадает: предупреждаем, чтобы инженер не упустил.
        if (null !== $p->thermal->peakTemperature && null !== $p->thermal->maxTemperature) {
            $warnings[] = sprintf(
                'Тепловая: подбор по пику %d °C; рабочая %d °C в фильтр не вошла (одна ось температуры).',
                $p->thermal->peakTemperature,
                $p->thermal->maxTemperature,
            );
        }

        $input = new CoatingSystemsFilterInput(
            search: $p->search,
            substrates: $p->substrates,
            environment: $p->environment,
            standard: $p->standard,
            category: $p->category,
            durability: $p->durability,
            substanceIds: $substanceIds,
            applicationMinTempFrom: $p->applicationMinTemp?->from,
            applicationMinTempTo: $p->applicationMinTemp?->to,
            minApplicationTimeFromMinutes: $p->minApplicationTimeAt20?->from, // промпт уже отдаёт минуты = ед. домена
            minApplicationTimeToMinutes: $p->minApplicationTimeAt20?->to,
            // Флаг выбирает колонку сравнения (CoatingSystemFinder): пик → *_peak_max, рабочая → *_continuous_max.
            // Поэтому температуру спариваем с её колонкой: задан пик → он и лимит выживания, иначе рабочая.
            thermalTemperature: $p->thermal->peakTemperature ?? $p->thermal->maxTemperature,
            thermalEnvironment: $p->thermal->environment,
            thermalIncludingPeak: null !== $p->thermal->peakTemperature,
            substanceTemperature: $p->chemicalResistance->maxTemperature,
            limit: self::ANSWER_LIMIT,
        );

        return new MappedSystemFilter($input, $warnings);
    }
}
