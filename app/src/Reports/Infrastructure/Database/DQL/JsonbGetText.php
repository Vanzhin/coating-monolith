<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: JSONB_GET_TEXT(r.project, 'title')
 * SQL: (r.project ->> 'title').
 *
 * Достаёт текстовое поле из JSON-колонки. NULL для отсутствующего ключа/колонки.
 */
final class JsonbGetText extends FunctionNode
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
            '(%s ->> %s)',
            $this->field->dispatch($sqlWalker),
            $this->key->dispatch($sqlWalker),
        );
    }
}
