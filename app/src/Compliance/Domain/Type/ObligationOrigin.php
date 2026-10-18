<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/**
 * Источник строки обязанности в проекции мониторинга: норменная позиция требования либо персональная
 * (off-norm) декларация, заведённая прямо на карточке человека. Пишется в колонку `origin` read-модели
 * ({@see \App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection::origin()}) строкой —
 * {@see self::value}.
 */
enum ObligationOrigin: string
{
    case Norm = 'norm';
    case Personal = 'personal';
}
