<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: TS_RANK_CD(searchVector, tsquery) -> SQL: ts_rank_cd(searchVector, tsquery)
 * Cover-density ranking — учитывает близость слов в документе.
 */
final class TsRankCd extends FunctionNode
{
    private Node $vector;
    private Node $query;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->vector = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->query = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'ts_rank_cd(%s, %s)',
            $this->vector->dispatch($sqlWalker),
            $this->query->dispatch($sqlWalker),
        );
    }
}
