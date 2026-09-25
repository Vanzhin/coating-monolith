<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RenderedDocument;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Domain\Templating\TemplateRenderer;
use App\Shared\Domain\Templating\TemplateVariable;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\Templating\ValidationResult;
use App\Shared\Infrastructure\Exception\AppException;
use PhpOffice\PhpWord\Exception\Exception as PhpWordException;

/**
 * Драйвер docx-шаблонов на PhpWord. Возможности: text + image + optional block + inline optional segment.
 *
 * Опциональность:
 *  - плейсхолдер {{name?}} — опциональный «на месте» (нет данных → чистим ТОЛЬКО плейсхолдер, литерал
 *    вокруг остаётся);
 *  - блок {{opt_x}} … {{/opt_x}} — регион (абзацного уровня) удаляется целиком, если данных внутри нет
 *    (presence-driven, all-or-none). Маркеры блока стоят каждый в своём абзаце;
 *  - инлайн-сегмент {{?name}} … {{/?name}} — вырезает литерал ВМЕСТЕ с плейсхолдером, когда данных нет
 *    (то, что {{name?}} и абзацный блок не умеют). Работает в одну строку/ячейку. Критерий по name:
 *    верхний уровень → has(name) (для повторяемой группы = есть строки, годится обернуть заголовок над
 *    списком); внутри повторяемой строки/блока → непустое подполе строки (по #i).
 *    НЕ разворачивает повтор — для «регион повторяется по строкам» это блок {{group}}…{{/group}}.
 *    Внутри повтора имя сегмента = ключ подполя, НЕ имя группы.
 */
final class DocxTemplateRenderer implements TemplateRenderer
{
    private const DEFAULT_MAX_IMAGE_WIDTH_PX = 600;

    /** Предохранитель от бесконечного цикла при обработке повторяемой группы в нескольких местах. */
    private const MAX_REPEAT_OCCURRENCES = 100;

    public function supports(TemplateFile $template): bool
    {
        return TemplateFormat::Docx === $template->format;
    }

    public function variables(TemplateFile $template): array
    {
        $parsed = $this->parse($this->load($template));

        $optionalByName = [];
        foreach ($parsed['values'] as $value) {
            $optionalByName[$value['logical']] = ($optionalByName[$value['logical']] ?? false) || $value['optional'];
        }

        $variables = [];
        foreach ($optionalByName as $name => $optional) {
            $variables[] = new TemplateVariable((string) $name, $optional);
        }

        return $variables;
    }

    public function validate(TemplateFile $template, RenderData $data): ValidationResult
    {
        $parsed = $this->parse($this->load($template));
        $states = $this->blockStates($parsed['blocks'], $data);

        $missing = [];
        $skipped = [];
        $unresolvedImages = [];
        $invalidBlocks = [];
        $templateNames = [];
        $seen = [];

        foreach ($states as $name => $state) {
            if (isset($parsed['repeats'][$name])) {
                continue; // повторяемые группы (блок или строка) — отдельная проверка ниже, по факту строк
            }
            if ('partial' === $state) {
                $invalidBlocks[] = $name;
            } elseif ('missing' === $state) {
                $missing[] = $name;
            }
        }

        foreach ($parsed['values'] as $value) {
            $logical = $value['logical'];
            $templateNames[$logical] = true;

            if (isset($seen[$logical])) {
                continue;
            }
            $seen[$logical] = true;

            $block = $value['block'];
            if (null !== $block) {
                $state = $states[$block] ?? 'delete';
                if ('delete' === $state) {
                    $skipped[] = $logical;
                } elseif ('keep' === $state) {
                    $held = $data->get($logical);
                    if ($held instanceof ImageValue && !is_readable($held->path)) {
                        $skipped[] = $logical;
                    }
                }

                continue;
            }

            $held = $data->get($logical);
            if (null === $held) {
                if ($value['optional']) {
                    $skipped[] = $logical;
                } else {
                    $missing[] = $logical;
                }

                continue;
            }

            if ($held instanceof ImageValue && !is_readable($held->path)) {
                if ($value['optional']) {
                    $skipped[] = $logical;
                } else {
                    $unresolvedImages[] = $logical;
                }
            }
        }

        // Повторяемые группы: шаблон их «знает» (строка таблицы или {{group}}…{{/group}} блок). Строка
        // таблицы маркеров не имеет — опциональна по природе, пустой список допустим (строка удалится),
        // в missing/skipped не попадает (поведение не меняем). Блок-повтор без строк — единообразно с
        // одиночным плейсхолдером/регионом: строгий ({{group}}…{{/group}}) → missing, опциональный
        // ({{group?}}…{{/group?}}) → тихо skipped. Строки есть — не трогаем (рендерится как обычно).
        foreach (array_keys($parsed['repeats']) as $group) {
            $templateNames[$group] = true;

            $block = $parsed['blocks'][$group] ?? null;
            if (null === $block) {
                continue; // строка таблицы — не блок, опциональна по природе
            }

            $value = $data->get($group);
            $hasRows = $value instanceof RepeatValue && [] !== $value->rows;
            if ($hasRows) {
                continue;
            }

            if ($block['optional']) {
                $skipped[] = $group;
            } else {
                $missing[] = $group;
            }
        }

        $unused = array_values(array_diff($data->variableNames(), array_keys($templateNames)));

        return new ValidationResult(
            missing: array_values(array_unique($missing)),
            unresolvedImages: array_values(array_unique($unresolvedImages)),
            invalidBlocks: array_values(array_unique($invalidBlocks)),
            skipped: array_values(array_unique($skipped)),
            unused: $unused,
        );
    }

    public function render(TemplateFile $template, RenderData $data): RenderedDocument
    {
        $result = $this->validate($template, $data);
        if (!$result->isValid()) {
            throw new AppException($this->invalidMessage($result), log: ['missing' => $result->missing, 'unresolvedImages' => $result->unresolvedImages, 'invalidBlocks' => $result->invalidBlocks]);
        }

        $processor = $this->load($template);
        $parsed = $this->parse($processor);
        $states = $this->blockStates($parsed['blocks'], $data);

        foreach ($states as $name => $state) {
            if (isset($parsed['repeats'][$name])) {
                continue; // повторяемый блок — обрабатывается ниже (cloneBlock по количеству)
            }
            $marker = $this->phpWordBlockMarker($name, $parsed['blocks'][$name]['optional']);
            if ('keep' === $state) {
                $processor->cloneBlock($marker, 1);
            } else {
                $processor->deleteBlock($marker);
            }
        }

        foreach ($parsed['values'] as $value) {
            $block = $value['block'];
            if (null !== $block && 'keep' !== ($states[$block] ?? 'delete')) {
                continue; // блок удалён — токен уже вырезан
            }
            $this->fill($processor, $value['token'], $value['logical'], $data);
        }

        // Повторяемые группы {{group.sub}}: обёрнуты маркерами {{group}}…{{/group}} → список Word
        // (cloneBlock); иначе — строка таблицы (cloneRow). Пусто → регион/строка удаляются.
        // Кривой шаблон (точечный токен не в строке таблицы/блоке, маркеры не абзацами) роняет PhpWord —
        // ловим и отдаём человекочитаемую ошибку вместо 500.
        try {
            foreach ($parsed['repeats'] as $group => $info) {
                $value = $data->get($group);
                $rows = $value instanceof RepeatValue ? $value->rows : [];
                if (isset($parsed['blocks'][$group])) {
                    $this->repeatBlock($processor, $group, $parsed['blocks'][$group]['optional'], $info['subs'], $rows);
                } else {
                    $this->repeatRow($processor, $info['anchor'], $group, $info['subs'], $rows);
                }
            }
        } catch (PhpWordException $e) {
            throw new AppException('Шаблон: не удалось обработать повторяемую группу. Плейсхолдеры {{группа.поле}} должны быть в одной строке таблицы, либо в блоке {{группа}}…{{/группа}} (маркеры — каждый в своём абзаце).', log: ['error' => $e->getMessage()], previous: $e);
        }

        // Инлайновые сегменты верхнего уровня {{?name}}…{{/?name}}: строго ПОСЛЕ повторов — внутри повтора
        // сегменты уже сняты по #i (resolveRowSegments), а top-level regex индексированные не трогает. Есть
        // поле/группа (has) → раскрыть регион, нет → вырезать целиком (заголовок над пустым списком и т.п.).
        $segmentPresence = [];
        foreach ($parsed['segments'] as $segmentName) {
            $value = $data->get($segmentName);
            // группа «присутствует», только если в ней есть строки (пустой RepeatValue → сегмент вырезать)
            $segmentPresence[$segmentName] = $value instanceof RepeatValue ? [] !== $value->rows : $data->has($segmentName);
        }
        $processor->resolveTopLevelSegments($segmentPresence);

        // Guard: в ТЕЛЕ не должно остаться сырых плейсхолдеров. Остаток = кривой шаблон (группы делят
        // строку, subs в разных строках, маркеры не оформлены) — тихая порча документа. Колонтитулы не
        // проверяем: повтор там не поддержан, cloneRow/cloneBlock их не трогают (см. normalizeMacros).
        $leftover = $processor->orderedMacros();
        if ([] !== $leftover) {
            throw new AppException(sprintf('Шаблон: не заполнены плейсхолдеры: %s. Проверьте оформление повторяемых групп/блоков.', implode(', ', $leftover)), log: ['leftover' => $leftover]);
        }

        return new RenderedDocument($this->toBytes($processor), TemplateFormat::Docx);
    }

    private function fill(DocxMacroProcessor $processor, string $token, string $logical, RenderData $data): void
    {
        $value = $data->get($logical);

        if (null === $value) {
            $processor->setValue($token, ''); // опциональный пропущенный — чистим

            return;
        }
        if ($value instanceof TextValue) {
            $processor->setValue($token, $value->value);

            return;
        }
        if ($value instanceof ImageValue) {
            if (!is_readable($value->path)) {
                $processor->setValue($token, ''); // опц. картинка с битым файлом (validate → skipped)

                return;
            }
            $processor->setImageValue($token, $this->imageOptions($value));
        }
    }

    /**
     * Повтор строкой таблицы (cloneRow): клонирует `<w:tr>` с anchor по количеству и заполняет
     * `{{group.sub#i}}`. Пусто → удаляет строку-шаблон (единственная строка → уйдёт вся таблица).
     * Одна группа может встречаться в НЕСКОЛЬКИХ таблицах — обрабатываем ВСЕ вхождения (cloneRow берёт
     * первое сырое, поэтому крутим, пока anchor ещё есть в документе).
     *
     * @param list<string>                $subs
     * @param list<array<string, string>> $rows
     */
    private function repeatRow(DocxMacroProcessor $processor, string $anchor, string $group, array $subs, array $rows): void
    {
        $mapped = [];
        foreach ($rows as $row) {
            $entry = [];
            foreach ($subs as $sub) {
                $entry[$group.'.'.$sub] = $row[$sub] ?? '';
            }
            $mapped[] = $entry;
        }

        $guard = 0;
        while (in_array($anchor, $processor->orderedMacros(), true) && ++$guard <= self::MAX_REPEAT_OCCURRENCES) {
            if ([] === $mapped) {
                $processor->deleteRow($anchor);
            } else {
                $processor->cloneRowAndSetValues($anchor, $mapped);
            }
        }

        // Инлайновые сегменты подполей ({{?sub#i}}…{{/?sub#i}}) — вырезать по пустоте подполя строки.
        $processor->resolveRowSegments($rows);
    }

    /**
     * Повтор блоком-абзацем (cloneBlock): клонирует регион {{group}}…{{/group}} (либо {{group?}}…{{/group?}}
     * для опционального повтора) по количеству (список Word — нумерацию проставит сам), заполняет
     * `{{group.sub#i}}`. Пусто → удаляет регион. Группа может встречаться в НЕСКОЛЬКИХ местах — крутим,
     * пока маркер группы ещё есть.
     *
     * @param list<string>                $subs
     * @param list<array<string, string>> $rows
     */
    private function repeatBlock(DocxMacroProcessor $processor, string $group, bool $optional, array $subs, array $rows): void
    {
        // literalMarker — как токен реально выглядит в тексте документа (сверка с orderedMacros());
        // regexMarker — что передать в PhpWord cloneBlock()/deleteBlock() (см. phpWordBlockMarker()).
        $literalMarker = $optional ? $group.'?' : $group;
        $regexMarker = $this->phpWordBlockMarker($group, $optional);

        $guard = 0;
        while (in_array($literalMarker, $processor->orderedMacros(), true) && ++$guard <= self::MAX_REPEAT_OCCURRENCES) {
            if ([] === $rows) {
                $processor->deleteBlock($regexMarker);

                continue;
            }
            $processor->cloneBlock($regexMarker, count($rows), true, true); // indexVariables → {{group.sub#i}}
            foreach ($rows as $i => $row) {
                $rowNumber = $i + 1;
                foreach ($subs as $sub) {
                    $processor->setValue($group.'.'.$sub.'#'.$rowNumber, $row[$sub] ?? '');
                }
            }
        }

        // Инлайновые сегменты подполей ({{?sub#i}}…{{/?sub#i}}) — вырезать по пустоте подполя строки.
        $processor->resolveRowSegments($rows);
    }

    /**
     * Литерал маркера блока для PhpWord cloneBlock()/deleteBlock(). PhpWord вставляет $blockname
     * НАПРЯМУЮ в свой internal-regex (без preg_quote) — для строгого блока логическое имя 'name' совпадает
     * с литералом маркера {{name}}, поэтому ничего экранировать не нужно. Для опционального региона
     * литерал в документе — {{name?}}: если передать 'name?' как есть, PhpWord трактует '?' как квантификатор
     * regex (0-или-1 предыдущего символа), а не литеральный вопрос, и НЕ находит маркер — блок не удаляется
     * и не клонируется, документ рвётся на }}-хвосте (это и был баг обрыва). Экранируем '?' backslash'ем,
     * чтобы PhpWord искал его буквально.
     */
    private function phpWordBlockMarker(string $logical, bool $optional): string
    {
        return $optional ? $logical.'\?' : $logical;
    }

    /**
     * @return array{path: string, ratio: bool, width?: int, height?: int}
     */
    private function imageOptions(ImageValue $image): array
    {
        $options = ['path' => $image->path, 'ratio' => true];

        if (null !== $image->width) {
            $options['width'] = $image->width;
        }
        if (null !== $image->height) {
            $options['height'] = $image->height;
        }
        if (null === $image->width && null === $image->height) {
            $size = @getimagesize($image->path);
            $natural = is_array($size) ? (int) $size[0] : 0;
            $options['width'] = ($natural > 0 && $natural <= self::DEFAULT_MAX_IMAGE_WIDTH_PX)
                ? $natural
                : self::DEFAULT_MAX_IMAGE_WIDTH_PX;
        }

        return $options;
    }

    /**
     * Разбор шаблона в упорядоченные значения и блоки.
     *
     * @return array{
     *     values: list<array{logical: string, token: string, optional: bool, block: string|null}>,
     *     blocks: array<string, array{optional: bool, values: list<string>}>,
     *     repeats: array<string, array{subs: list<string>, anchor: string}>,
     *     segments: list<string>
     * }
     */
    private function parse(DocxMacroProcessor $processor): array
    {
        $mainContents = $processor->orderedMacros();     // тело, по порядку — для структуры блоков
        $allTokens = $processor->getVariables();          // тело + колонтитулы — полный набор переменных

        // маркеры блоков распознаём по паре {{name}} / {{/name}} ИЛИ {{name?}} / {{/name?}} (опц. регион);
        // {{/?name}} — закрытие инлайн-сегмента, не блок (иначе substr дал бы фантомный блок "?name").
        // Значение в $closeSet = optional-флаг региона (снят с `/name?` через $closeSet[rtrim($n,'?')]).
        $closeSet = [];
        foreach ($allTokens as $token) {
            if (str_starts_with($token, '/?')) {
                continue;
            }
            if (str_starts_with($token, '/')) {
                $name = substr($token, 1);
                $optional = str_ends_with($name, '?');
                $closeSet[$optional ? substr($name, 0, -1) : $name] = $optional;
            }
        }

        // Точная литеральная форма открывающего маркера каждого блока: 'name' для строгого, 'name?' для
        // опционального. Матчим ТОЧНО (не rtrim-ом), иначе плейсхолдер {{note}} внутри блока {{note?}} с
        // тем же логическим именем ложно распознаётся как повторное открытие региона.
        $blockOpens = [];
        foreach ($closeSet as $logical => $optional) {
            $blockOpens[$optional ? $logical.'?' : $logical] = $logical;
        }

        // повторяемые группы: точечные токены {{group.sub}} (строка таблицы). Все токены группы — в одной
        // строке; anchor — любой из них (cloneRow индексирует всю строку). Из flat-значений исключаем.
        // ТОЛЬКО по телу (orderedMacros): cloneRow/cloneBlock работают по tempDocumentMainPart, повтор в
        // колонтитуле не поддержан (иначе anchor есть, а cloneRow не найдёт → PhpWord-Exception → 500).
        $repeats = [];
        $repeatMembers = [];
        foreach ($mainContents as $token) {
            if (1 === preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)$/', $token, $m)) {
                $group = $m[1];
                $sub = $m[2];
                $repeatMembers[$token] = true;
                $repeats[$group] ??= ['subs' => [], 'anchor' => $token];
                if (!in_array($sub, $repeats[$group]['subs'], true)) {
                    $repeats[$group]['subs'][] = $sub;
                }
            }
        }

        $values = [];
        $blocks = [];
        $stack = [];
        $segmentStack = [];
        $segmentNames = [];
        $seenLogical = [];

        // 1) тело: значения + membership блоков в порядке появления
        foreach ($mainContents as $content) {
            if (str_starts_with($content, '/?')) {
                array_pop($segmentStack); // закрытие инлайн-сегмента {{/?name}}
                continue;
            }
            if (1 === preg_match('/^\?([a-z0-9_]+)$/', $content, $seg)) {
                $segmentStack[] = $seg[1]; // открытие инлайн-сегмента {{?name}}
                $segmentNames[$seg[1]] = true;
                continue;
            }
            if (str_starts_with($content, '/')) {
                array_pop($stack);
                continue;
            }
            if (isset($blockOpens[$content])) {
                $logical = $blockOpens[$content];
                $stack[] = $logical;
                $blocks[$logical] ??= ['optional' => $closeSet[$logical], 'values' => []];
                continue;
            }
            if (isset($repeatMembers[$content])) {
                continue; // член повторяемой группы — не flat-значение и не член блока
            }

            $optionalMark = str_ends_with($content, '?');
            $logical = $optionalMark ? substr($content, 0, -1) : $content;
            $block = [] === $stack ? null : $stack[array_key_last($stack)];

            $values[] = [
                'logical' => $logical,
                'token' => $content,
                // внутри инлайн-сегмента значение опционально (нет данных → сегмент вырежется, а не missing).
                // Внутри любого блока — тоже опционально (миссинг-поведение для строгих блоков — след. задача).
                'optional' => $optionalMark || null !== $block || [] !== $segmentStack,
                'block' => $block,
            ];
            $seenLogical[$logical] = true;

            foreach ($stack as $enclosing) {
                $blocks[$enclosing]['values'][] = $logical;
            }
        }

        // 2) колонтитулы/сноски: плейсхолдеры вне тела — как обычные top-level значения
        //    (опциональные блоки поддерживаются только в теле документа)
        foreach ($allTokens as $token) {
            if (str_starts_with($token, '/') || str_starts_with($token, '?') || isset($blockOpens[$token])
                || isset($repeatMembers[$token]) || 1 === preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $token)) {
                continue; // маркер блока/сегмента / член повторяемой группы / точечный синтаксис — не flat-значение
            }

            $optionalMark = str_ends_with($token, '?');
            $logical = $optionalMark ? substr($token, 0, -1) : $token;
            if (isset($seenLogical[$logical])) {
                continue;
            }
            $seenLogical[$logical] = true;

            $values[] = [
                'logical' => $logical,
                'token' => $token,
                'optional' => $optionalMark,
                'block' => null,
            ];
        }

        return ['values' => $values, 'blocks' => $blocks, 'repeats' => $repeats, 'segments' => array_keys($segmentNames)];
    }

    /**
     * @param array<string, array{optional: bool, values: list<string>}> $blocks
     *
     * @return array<string, 'keep'|'delete'|'missing'|'partial'>
     */
    private function blockStates(array $blocks, RenderData $data): array
    {
        $states = [];
        foreach ($blocks as $name => $info) {
            $unique = array_values(array_unique($info['values']));
            $present = 0;
            foreach ($unique as $member) {
                if ($data->has($member)) {
                    ++$present;
                }
            }

            if (0 === $present) {
                // Пусто: опциональный регион тихо выпадает, строгий блокирует сборку файла — единообразно
                // с одиночным плейсхолдером ({{x}} без данных → missing, {{x?}} → skipped).
                $states[$name] = $info['optional'] ? 'delete' : 'missing';
            } elseif ($present === count($unique)) {
                $states[$name] = 'keep';
            } else {
                $states[$name] = 'partial';
            }
        }

        return $states;
    }

    private function invalidMessage(ValidationResult $result): string
    {
        $parts = [];
        if ([] !== $result->missing) {
            $parts[] = 'не заполнены обязательные поля: '.implode(', ', $result->missing);
        }
        if ([] !== $result->unresolvedImages) {
            $parts[] = 'недоступны файлы картинок: '.implode(', ', $result->unresolvedImages);
        }
        if ([] !== $result->invalidBlocks) {
            $parts[] = 'частично заполнены блоки: '.implode(', ', $result->invalidBlocks);
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    private function load(TemplateFile $template): DocxMacroProcessor
    {
        if (!is_readable($template->path)) {
            throw new AppException(sprintf('Файл шаблона недоступен: «%s».', $template->path));
        }

        try {
            $processor = new DocxMacroProcessor($template->path);
            $processor->normalizeMacros(); // схлопнуть разбитые Word'ом макросы (иначе cloneRow/setValue не найдут)

            return $processor;
        } catch (\Throwable $e) {
            throw new AppException('Не удалось открыть docx-шаблон.', log: ['path' => $template->path], previous: $e);
        }
    }

    private function toBytes(DocxMacroProcessor $processor): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx_out_');
        if (false === $path) {
            throw new AppException('Не удалось создать временный файл для docx.');
        }

        try {
            $processor->saveAs($path);
            $bytes = file_get_contents($path);
            if (false === $bytes) {
                throw new AppException('Не удалось прочитать сгенерированный docx.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }
}
