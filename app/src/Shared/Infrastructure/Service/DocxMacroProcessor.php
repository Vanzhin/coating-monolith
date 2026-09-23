<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * TemplateProcessor с двойными скобками {{ }} вместо дефолтных ${ }, плюс доступ к упорядоченному
 * списку макросов документа — нужен драйверу, чтобы разобрать блоки и membership переменных.
 *
 * Включаем XML-экранирование значений (по умолчанию в PhpWord выключено): без него любой `&`/`<`/`>`
 * в данных отчёта (ФИО «ООО "Ромашка" & Партнёры», коммент «t < 5 °C») ломает word/document.xml —
 * Word отказывается открывать файл. Экранирование бьёт только по setValue; переносы `\n`→`<w:br/>`
 * ставятся PhpWord ПОСЛЕ экранирования, поэтому не задеваются.
 */
final class DocxMacroProcessor extends TemplateProcessor
{
    private bool $outputEscapingWasEnabled = false;

    public function __construct(string $documentTemplate)
    {
        self::$macroOpeningChars = '{{';
        self::$macroClosingChars = '}}';
        $this->outputEscapingWasEnabled = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        parent::__construct($documentTemplate);
    }

    /**
     * Макро-скобки и флаг экранирования в PhpWord — глобальные static. Восстанавливаем прежние значения
     * при уничтожении процессора, чтобы наш выбор {{ }} и экранирование не протекали на другой код.
     */
    public function __destruct()
    {
        parent::__destruct();

        self::$macroOpeningChars = '${';
        self::$macroClosingChars = '}';
        Settings::setOutputEscapingEnabled($this->outputEscapingWasEnabled);
    }

    /**
     * Готовит шаблон к обработке: (1) схлопывает макросы, разбитые Word'ом по run'ам (`{{name}}` →
     * `{{name</w:t>…<w:t>}}`) — иначе cloneRow/cloneBlock/setValue не находят плейсхолдер поиском по
     * сырому XML; (2) убирает `?` у точечных токенов повторяемых групп (`{{group.sub?}}` → `{{group.sub}}`)
     * — у repeat-групп `?` бессмысленен (они опциональны по природе), а иначе токен не распознаётся и
     * остаётся сырым. Применяем к телу и колонтитулам сразу после загрузки шаблона.
     */
    public function normalizeMacros(): void
    {
        $dotted = '/'.preg_quote(self::$macroOpeningChars, '/').'([a-z0-9_]+\.[a-z0-9_]+)\?'.preg_quote(self::$macroClosingChars, '/').'/';
        $replacement = self::$macroOpeningChars.'$1'.self::$macroClosingChars;
        $prepare = fn (string $part): string => (string) preg_replace($dotted, $replacement, $this->fixBrokenMacros($part));

        $this->tempDocumentMainPart = $prepare($this->tempDocumentMainPart);
        foreach ($this->tempDocumentHeaders as $index => $part) {
            $this->tempDocumentHeaders[$index] = $prepare($part);
        }
        foreach ($this->tempDocumentFooters as $index => $part) {
            $this->tempDocumentFooters[$index] = $prepare($part);
        }
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

    /**
     * Инлайновые опциональные сегменты верхнего уровня: `{{?name}}…{{/?name}}`. Вырезают литеральный
     * текст вокруг плейсхолдера, когда данных нет (чего `{{name?}}` и абзацный блок `{{opt}}` не умеют).
     * `$present[name] === true` → маркеры снимаются, внутренний текст остаётся (и дозаполняется обычным
     * setValue); иначе регион вырезается целиком. Индексированные `{{?name#i}}` НЕ трогаем (это сегменты
     * повторяемых групп — их снимает resolveRowSegments), поэтому имя ограничено `[a-z0-9_]+` без `#`.
     * Вызывать ПОСЛЕ обработки повторов.
     *
     * @param array<string, bool> $present
     */
    public function resolveTopLevelSegments(array $present): void
    {
        $open = preg_quote(self::$macroOpeningChars, '/');
        $close = preg_quote(self::$macroClosingChars, '/');
        $pattern = '/'.$open.'\?([a-z0-9_]+)'.$close.'(.*?)'.$open.'\/\?\1'.$close.'/su';

        $this->tempDocumentMainPart = (string) preg_replace_callback(
            $pattern,
            static fn (array $m): string => ($present[$m[1]] ?? false) ? $m[2] : '',
            $this->tempDocumentMainPart,
        );
    }

    /**
     * Инлайновые опциональные сегменты ВНУТРИ клонированной повторяемой группы: `{{?sub#i}}…{{/?sub#i}}`.
     * PhpWord при cloneRow/cloneBlock проиндексировал маркеры `#i` вместе с плейсхолдерами, поэтому номер
     * строки берём прямо из маркера: непустое `rows[i-1][sub]` → маркеры снять (внутренний текст, уже
     * заполненный, остаётся), пусто → регион вырезать. Вызывать сразу после клонирования группы.
     *
     * @param list<array<string, string>> $rows
     */
    public function resolveRowSegments(array $rows): void
    {
        $open = preg_quote(self::$macroOpeningChars, '/');
        $close = preg_quote(self::$macroClosingChars, '/');
        $pattern = '/'.$open.'\?([a-z0-9_]+)#(\d+)'.$close.'(.*?)'.$open.'\/\?\1#\2'.$close.'/su';

        $this->tempDocumentMainPart = (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($rows): string {
                $sub = $m[1];
                $rowIndex = (int) $m[2] - 1;
                $value = $rows[$rowIndex][$sub] ?? '';

                return '' === trim((string) $value) ? '' : $m[3];
            },
            $this->tempDocumentMainPart,
        );
    }
}
