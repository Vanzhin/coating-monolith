<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: TO_TSVECTOR('russian', t.title) -> SQL: to_tsvector('russian', t.title)
 */
final class ToTsvector extends FunctionNode
{
    private Node $config;
    private Node $document;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->config = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->document = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'to_tsvector(%s, %s)',
            $this->config->dispatch($sqlWalker),
            $this->document->dispatch($sqlWalker),
        );
    }
}
