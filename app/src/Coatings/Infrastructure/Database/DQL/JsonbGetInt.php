<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: JSONB_GET_INT(cc.dryHeatExposure, 'continuous_max')
 * SQL: (cc.dry_heat_exposure ->> 'continuous_max')::int.
 *
 * Достаёт числовое поле из JSONB-колонки, приводит к int. NULL для отсутствующего
 * ключа. Использовать только на nullable JSONB-колонках, обёрнутых в IS NOT NULL.
 */
final class JsonbGetInt extends FunctionNode
{
    private Node $field;
    private Node $key;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->StateFieldPathExpression();
        $parser->match(TokenType::T_COMMA);
        $this->key = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            '(%s ->> %s)::int',
            $this->field->dispatch($sqlWalker),
            $this->key->dispatch($sqlWalker),
        );
    }
}
