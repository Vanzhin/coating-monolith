<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: TO_TSQUERY('russian', :tsquery) -> SQL: to_tsquery('russian', :tsquery)
 * В отличие от websearch_to_tsquery, принимает уже подготовленную tsquery-строку
 * (например, 'быстросох:*' для префиксного поиска). Подготовка — на стороне приложения.
 */
final class ToTsquery extends FunctionNode
{
    private Node $config;
    private Node $query;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->config = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->query = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'to_tsquery(%s, %s)',
            $this->config->dispatch($sqlWalker),
            $this->query->dispatch($sqlWalker),
        );
    }
}
