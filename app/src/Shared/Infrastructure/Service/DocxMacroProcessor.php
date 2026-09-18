<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * TemplateProcessor с двойными скобками {{ }} вместо дефолтных ${ }, плюс доступ к упорядоченному
 * списку макросов документа — нужен драйверу, чтобы разобрать блоки и membership переменных.
 */
final class DocxMacroProcessor extends TemplateProcessor
{
    public function __construct(string $documentTemplate)
    {
        self::$macroOpeningChars = '{{';
        self::$macroClosingChars = '}}';

        parent::__construct($documentTemplate);
    }

    /**
     * Макро-скобки в PhpWord — глобальный static. Восстанавливаем дефолт ${ } при уничтожении
     * процессора, чтобы наш выбор {{ }} не протекал на другой код, использующий TemplateProcessor.
     */
    public function __destruct()
    {
        parent::__destruct();

        self::$macroOpeningChars = '${';
        self::$macroClosingChars = '}';
    }

    /**
     * Содержимое макросов в порядке появления в документе:
     * например ['object', 'opt_comp_b', 'comp_b_batch', '/opt_comp_b', 'comment?'].
     *
     * @return list<string>
     */
    public function orderedMacros(): array
    {
        $xml = $this->fixBrokenMacros($this->tempDocumentMainPart);

        if (0 === preg_match_all('/\{\{(.*?)\}\}/', $xml, $matches)) {
            return [];
        }

        return array_values($matches[1]);
    }
}
