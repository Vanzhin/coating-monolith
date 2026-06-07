<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: GREATEST(a, b, ...) -> SQL: GREATEST(a, b, ...)
 * Принимает 2+ аргументов, возвращает максимум.
 */
final class Greatest extends FunctionNode
{
    /** @var Node[] */
    private array $args = [];

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->args[] = $parser->StringPrimary();
        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->args[] = $parser->StringPrimary();
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $parts = array_map(fn(Node $arg) => $arg->dispatch($sqlWalker), $this->args);

        return 'GREATEST(' . implode(', ', $parts) . ')';
    }
}
