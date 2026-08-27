<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

/**
 * Тип ЛКМ (основание покрытия). Хранится по ISO 12944-5; знает свои синонимы по ГОСТ 9825
 * и матрицу совместимости — какие основания можно наносить друг на друга.
 */
enum CoatingBase: string
{
    case AK = 'AK';     // Алкидные
    case AY = 'AY';     // Акриловые
    case ESI = 'ESI';   // Этилсиликатные
    case EP = 'EP';     // Эпоксидные
    case PUR = 'PUR';   // Полиуретановые
    case FEVE = 'FEVE'; // На основе фторированных полимеров
    case PAS = 'PAS';   // Полиаспартатные
    case PS = 'PS';     // Полисилоксановые

    /** Аббревиатура по ISO 12944-5 — то же что value enum'а. */
    public function iso(): string
    {
        return $this->value;
    }

    /**
     * Аббревиатуры по ГОСТ 9825. Пустой массив — стандарт ГОСТ не определяет код для этого типа.
     *
     * @return list<string>
     */
    public function gost(): array
    {
        return match ($this) {
            self::AK => ['ПФ', 'ГФ', 'АУ'],
            self::AY => ['АК', 'АС', 'ВД-АК'],
            self::ESI => [],
            self::EP => ['ЭП'],
            self::PUR => ['УР'],
            self::FEVE => ['ФП'],
            self::PAS => [],
            self::PS => ['КО'],
        };
    }

    /** Читаемое название типа на русском — для UI. */
    public function title(): string
    {
        return match ($this) {
            self::AK => 'Алкидное',
            self::AY => 'Акриловое',
            self::ESI => 'Этилсиликатное',
            self::EP => 'Эпоксидное',
            self::PUR => 'Полиуретановое',
            self::FEVE => 'На основе фторированных полимеров',
            self::PAS => 'Полиаспартатное',
            self::PS => 'Полисилоксановое',
        };
    }

    /**
     * Дефолтная максимальная температура эксплуатации в сухом тепле (°C) по типу связующего.
     * Служит fallback'ом для верхних пределов эксплуатации (continuous_max/peak_max),
     * когда у покрытия они не задокументированы. Не путать с температурой сушки (dryingMaxTemp).
     */
    public function defaultDryHeatMaxOperatingTemp(): int
    {
        return match ($this) {
            self::AY, self::FEVE, self::PAS => 50,
            self::AK, self::ESI, self::EP, self::PUR, self::PS => 120,
        };
    }

    /**
     * Ищет тип ЛКМ по аббревиатуре ГОСТ 9825 (например «ЭП», «УР»).
     * Сравнение без учёта регистра и пробелов. null — если такой ГОСТ-аббревиатуры нет.
     */
    public static function fromGost(string $gost): ?self
    {
        $needle = mb_strtoupper(trim($gost));
        if ('' === $needle) {
            return null;
        }
        foreach (self::cases() as $case) {
            foreach ($case->gost() as $abbr) {
                if (mb_strtoupper($abbr) === $needle) {
                    return $case;
                }
            }
        }

        return null;
    }

    /**
     * Матрица совместимости перекрытия (данные ISO 12944-5 / ГОСТ 34667.5): основания-грунтовки,
     * поверх которых можно нанести покрытие этого типа. Здесь только ДАННЫЕ — решение о совместимости
     * принимает единственная точка Coating::canBeAppliedOnTopOf (она учитывает ещё и пигмент/цинк).
     *
     * @return list<self>
     */
    public function allowedPrimers(): array
    {
        // Совместимость перекрытия по ISO 12944-5 (ГОСТ 34667.5): таблица F.1 (какая грунтовка
        // системы ложится поверх какой) + примечания к таблицам C.1–C.5 (FEVE/PAS/PS — топкоаты-
        // альтернативы PUR поверх EP/PUR/ESI). Пигментный нюанс (нельзя AK поверх цинкнаполненной
        // грунтовки) enum не выражает — он в Coating::canBecoveredBy через isZincRich.
        return match ($this) {
            self::AK => [self::AK, self::AY, self::EP, self::PUR],
            self::AY => [self::AY, self::AK, self::EP, self::PUR, self::ESI],
            self::ESI => [self::ESI],
            self::EP => [self::EP, self::PUR, self::ESI, self::AY],
            self::PUR => [self::EP, self::PUR, self::ESI, self::AY],
            self::FEVE => [self::FEVE, self::EP, self::PUR, self::ESI],
            self::PAS => [self::PAS, self::EP, self::PUR, self::ESI],
            self::PS => [self::PS, self::EP, self::PUR, self::ESI],
        };
    }
}
