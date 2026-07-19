<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

final class AliasesParser
{
    /** @return list<string> */
    public static function parse(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text))));
    }
}
