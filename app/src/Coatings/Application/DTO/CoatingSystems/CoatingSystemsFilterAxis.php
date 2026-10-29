<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Единый словарь человекочитаемых подписей осей фильтра подбора: ключ примитивного входа
 * (CoatingSystemsFilterInput::toArray) → русская подпись. ОДНО место для подписей — им пользуются
 * и карточка запроса (describe), и дашборд точности. Сгруппированные оси (тепловая нагрузка, диапазоны)
 * делят одну подпись, чтобы потребитель мог схлопнуть их в одну строку. Пагинация/сортировка — не оси.
 */
enum CoatingSystemsFilterAxis: string
{
    case Substrates = 'substrates';
    case Environment = 'environment';
    case Standard = 'standard';
    case Category = 'category';
    case Durability = 'durability';
    case SubstanceIds = 'substanceIds';
    case SubstanceTemperature = 'substanceTemperature';
    case ThermalTemperature = 'thermalTemperature';
    case ThermalEnvironment = 'thermalEnvironment';
    case ThermalIncludingPeak = 'thermalIncludingPeak';
    case ApplicationMinTempFrom = 'applicationMinTempFrom';
    case ApplicationMinTempTo = 'applicationMinTempTo';
    case MinApplicationTimeFromMinutes = 'minApplicationTimeFromMinutes';
    case MinApplicationTimeToMinutes = 'minApplicationTimeToMinutes';
    case Search = 'search';
    case HasDocuments = 'hasDocuments';

    public function label(): string
    {
        return match ($this) {
            self::Substrates => 'Подложка',
            self::Environment => 'Среда',
            self::Standard => 'Стандарт',
            self::Category => 'Категория коррозивности',
            self::Durability => 'Долговечность',
            self::SubstanceIds => 'Вещества',
            self::SubstanceTemperature => 'Температура у вещества',
            self::ThermalTemperature, self::ThermalEnvironment, self::ThermalIncludingPeak => 'Тепловая нагрузка',
            self::ApplicationMinTempFrom, self::ApplicationMinTempTo => 'Температура нанесения',
            self::MinApplicationTimeFromMinutes, self::MinApplicationTimeToMinutes => 'Время сборки при 20 °C',
            self::Search => 'Поиск по тексту',
            self::HasDocuments => 'С документами',
        };
    }

    public static function labelFor(string $key): ?string
    {
        return self::tryFrom($key)?->label();
    }

    /** @return array<string, string> ключ оси → подпись (для словаря потребителям) */
    public static function labels(): array
    {
        $out = [];
        foreach (self::cases() as $axis) {
            $out[$axis->value] = $axis->label();
        }

        return $out;
    }
}
