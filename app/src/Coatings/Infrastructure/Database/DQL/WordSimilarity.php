<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: WORD_SIMILARITY(:query, text) -> SQL: word_similarity(:query, text)
 * В отличие от similarity, считает похожесть к лучше всего совпадающему «слову»
 * внутри text — корректно работает на длинных описаниях, где similarity занижена
 * из-за большого количества несовпадающих триграмм.
 * Аргументы: (искомый запрос, столбец-текст).
 */
final class WordSimilarity extends FunctionNode
{
    private Node $needle;
    private Node $haystack;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->needle = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->haystack = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'word_similarity(%s, %s)',
            $this->needle->dispatch($sqlWalker),
            $this->haystack->dispatch($sqlWalker),
        );
    }
}
