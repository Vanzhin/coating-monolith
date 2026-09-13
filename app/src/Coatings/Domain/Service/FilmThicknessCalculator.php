<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Физика толщины лакокрасочной плёнки: связь мокрой (WFT) и сухой (DFT) через объёмный сухой
 * остаток с поправкой на разбавление. Живёт в домене как источник истины — фронт-калькулятор
 * дублирует формулу лишь ради офлайна. Переиспользуемо для дальнейшего (расход материала,
 * рабочие параметры систем).
 *
 * Разбавление D% (растворитель по объёму к краске) снижает эффективный сухой остаток:
 *   VS_эфф = VS / (1 + D/100)
 * Связь толщин:
 *   DFT = WFT × VS_эфф / 100      (сухая из мокрой)
 *   WFT = DFT × 100 / VS_эфф      (мокрая из сухой)
 *
 * Инварианты входов держат VO: проценты [0;100] — Percent, положительные толщины — PositiveNumber.
 */
final class FilmThicknessCalculator
{
    /** Мокрая плёнка (мкм), нужная чтобы получить заданную сухую при данных VS и разбавлении. */
    public function wetFromDry(Percent $volumeSolids, Percent $dilution, PositiveNumber $dryFilm): float
    {
        return $dryFilm->value() * 100 / $this->effectiveSolidsPercent($volumeSolids, $dilution);
    }

    /** Сухая плёнка (мкм) из заданной мокрой при данных VS и разбавлении. */
    public function dryFromWet(Percent $volumeSolids, Percent $dilution, PositiveNumber $wetFilm): float
    {
        return $wetFilm->value() * $this->effectiveSolidsPercent($volumeSolids, $dilution) / 100;
    }

    /**
     * Эффективный объёмный сухой остаток (%) с поправкой на разбавление. Публичный — сам по себе
     * полезен (напр. для будущего расчёта расхода). VS обязан быть > 0 (это делитель; Percent
     * допускает 0, но для плёнки нулевой сухой остаток бессмыслен).
     */
    public function effectiveSolidsPercent(Percent $volumeSolids, Percent $dilution): float
    {
        if ($volumeSolids->value() <= 0) {
            throw new AppException('Сухой остаток по объёму должен быть больше 0 %.');
        }

        return $volumeSolids->value() / (1 + $dilution->value() / 100);
    }
}
