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
     * Можно ли это покрытие наносить поверх покрытия с основанием $primer.
     * Семантика: $topCoat->canBeAppliedOver($primer).
     */
    public function canBeAppliedOnTopOf(self $primer): bool
    {
        return in_array($primer, $this->allowedPrimers(), true);
    }

    /**
     * Можно ли поверх этого основания нанести покрытие $topCoat.
     * Зеркально к canBeAppliedOnTopOf: $primer->canReceive($topCoat).
     */
    public function canBecoveredBy(self $topCoat): bool
    {
        return $topCoat->canBeAppliedOnTopOf($this);
    }

    /**
     * Список оснований, поверх которых данный тип ЛКМ можно наносить.
     * Заготовка: каждое основание совместимо как минимум само с собой.
     * Реальные правила совместимости заполняются вручную по справочной литературе.
     *
     * @return list<self>
     */
    private function allowedPrimers(): array
    {
        // todo записать совместимость
        return match ($this) {
            self::AK => [self::AK, self::AY, self::EP, self::PUR],
            self::AY => [self::AY, self::AK, self::EP, self::PUR, self::ESI],
            self::ESI => [self::ESI],
            self::EP => [self::EP, self::PUR, self::ESI],
            self::PUR => [self::EP, self::PUR, self::ESI],
            self::FEVE => [self::FEVE],
            self::PAS => [self::PAS],
            self::PS => [self::PS, self::EP, self::PUR, self::ESI],
        };
    }
}
