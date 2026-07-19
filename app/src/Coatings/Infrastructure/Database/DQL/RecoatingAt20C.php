<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: RECOATING_AT_20C(cc.minRecoatingInterval).
 *
 * Возвращает интервал перекрытия при +20 °C в минутах, интерполированный между
 * ближайшими точками серии `default`. Зеркалит домен (DryingTimeSeries::getPoint):
 *  - Если у покрытия есть точка ровно при 20 °C — берётся её time_in_minutes.
 *  - Иначе — линейная интерполяция между ближайшими точками ниже и выше 20 °C.
 *  - Игнорируются точки с time_in_minutes IS NULL (unknown) и = 0 (unlimited):
 *    эти семантики не участвуют в вычислении числового значения.
 *  - Если брекет-пары нет (единственная точка / все ниже / все выше) — NULL.
 *
 * JSONB-схема (см. RecoatingIntervalTree/DryingTimeSeries::jsonSerialize):
 *   { "default": [ {"temperature_at": int, "time_in_minutes": int|null}, ... ], "children": {...} }
 *
 * NULL результат вылетает из range-фильтров (WHERE ... BETWEEN N AND M). Использовать
 * только на IS NOT NULL guard'нутой колонке (для nullable maxRecoatingInterval).
 */
final class RecoatingAt20C extends FunctionNode
{
    private Node $field;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->StateFieldPathExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $field = $this->field->dispatch($sqlWalker);

        return sprintf(
            'COALESCE('
                .'(SELECT (pt->>%1$s)::int FROM jsonb_array_elements(%2$s->%3$s) pt '
                .'WHERE (pt->>%4$s)::int = 20 '
                .'AND pt->>%1$s IS NOT NULL AND (pt->>%1$s)::int > 0 LIMIT 1), '
                .'('
                .'SELECT low_v + (high_v - low_v) * (20 - low_t) / NULLIF(high_t - low_t, 0) '
                .'FROM '
                .'(SELECT (pt->>%4$s)::int AS low_t, (pt->>%1$s)::int AS low_v '
                .'FROM jsonb_array_elements(%2$s->%3$s) pt '
                .'WHERE pt->>%1$s IS NOT NULL AND (pt->>%1$s)::int > 0 '
                .'AND (pt->>%4$s)::int < 20 '
                .'ORDER BY (pt->>%4$s)::int DESC LIMIT 1) low, '
                .'(SELECT (pt->>%4$s)::int AS high_t, (pt->>%1$s)::int AS high_v '
                .'FROM jsonb_array_elements(%2$s->%3$s) pt '
                .'WHERE pt->>%1$s IS NOT NULL AND (pt->>%1$s)::int > 0 '
                .'AND (pt->>%4$s)::int > 20 '
                .'ORDER BY (pt->>%4$s)::int ASC LIMIT 1) high'
                .')'
            .')',
            "'time_in_minutes'",
            $field,
            "'default'",
            "'temperature_at'",
        );
    }
}
