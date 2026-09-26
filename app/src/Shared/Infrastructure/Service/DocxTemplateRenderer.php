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
 * Драйвер docx-шаблонов на PhpWord. Возможности: text + image + optional block + inline optional region.
 *
 * Опциональность:
 *  - плейсхолдер {{name?}} — опциональный «на месте» (нет данных → чистим ТОЛЬКО плейсхолдер, литерал
 *    вокруг остаётся);
 *  - регион {{name?}} … {{/name?}} — вырезается целиком, если данных нет (presence-driven, all-or-none).
 *    Форма зависит от того, в ОДНОМ ли `<w:p>` лежат оба маркера (parse() детектит автоматически):
 *    маркеры в РАЗНЫХ абзацах → блок абзацного уровня (PhpWord cloneBlock/deleteBlock); маркеры в ОДНОМ
 *    абзаце/ячейке → инлайн-регион (вырез регэкспом, DocxMacroProcessor::resolveInlineOptionalRegions) —
 *    то, что абзацный cloneBlock/deleteBlock физически не умеет (маркеры не стоят каждый в своём `<w:p>`);
 */
final class DocxTemplateRenderer implements TemplateRenderer
{
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

        // Инлайн-регионы ВНУТРИ строк/блоков повтора: точечное имя {{group.sub?}}…{{/group.sub?}}. Повтор
        // уже развёрнут выше (cloneRow/cloneBlock проставили #i на все макросы клона), поэтому резолвим
        // по-строчно: присутствие = подполе `sub` строки i непусто. Есть значение → «, датчик X» остаётся,
        // пусто → регион с сопроводительным литералом вырезается. До плоских регионов и до guard'а.
        $rowRegionPresence = [];
        foreach ($parsed['inlineRegions'] as $regionName) {
            if (1 !== preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)$/', $regionName, $m)) {
                continue; // плоский регион верхнего уровня — ниже
            }
            $groupValue = $data->get($m[1]);
            $rows = $groupValue instanceof RepeatValue ? $groupValue->rows : [];
            foreach ($rows as $i => $row) {
                $rowRegionPresence[$regionName.'#'.($i + 1)] = '' !== (string) ($row[$m[2]] ?? '');
            }
        }
        $processor->resolveIndexedInlineOptionalRegions($rowRegionPresence);

        // Инлайн-регионы верхнего уровня {{name?}}…{{/name?}} (оба маркера в одном абзаце — см. parse()/
        // isInlineRegion): значение внутри уже заполнено циклом fill() выше, здесь только снимаем/вырезаем
        // маркеры региона по presence. Не разворачивает повтор (только плоское поле верхнего уровня — область
        // задачи), поэтому порядок относительно блоков/повторов не важен, важно лишь ПОСЛЕ fill().
        $inlineRegionPresence = [];
        foreach ($parsed['inlineRegions'] as $inlineRegionName) {
            if (str_contains($inlineRegionName, '.')) {
                continue; // точечный регион строки повтора — уже разрешён resolveIndexedInlineOptionalRegions()
            }
            $inlineRegionPresence[$inlineRegionName] = $data->has($inlineRegionName);
        }
        $processor->resolveInlineOptionalRegions($inlineRegionPresence);

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
     * @param list<string>                           $subs
     * @param list<array<string, string|ImageValue>> $rows
     */
    private function repeatRow(DocxMacroProcessor $processor, string $anchor, string $group, array $subs, array $rows): void
    {
        // Есть ли в строках картиночные ячейки? Картинку нельзя залить через cloneRowAndSetValues
        // (он ставит только строки) — для таких строк идём через cloneRow + поячеечную заливку.
        $hasImage = false;
        foreach ($rows as $row) {
            foreach ($subs as $sub) {
                if (($row[$sub] ?? null) instanceof ImageValue) {
                    $hasImage = true;
                    break 2;
                }
            }
        }

        $guard = 0;
        if (!$hasImage) {
            // Только текст — прежний быстрый путь (без изменений).
            $mapped = [];
            foreach ($rows as $row) {
                $entry = [];
                foreach ($subs as $sub) {
                    $entry[$group.'.'.$sub] = (string) ($row[$sub] ?? '');
                }
                $mapped[] = $entry;
            }
            while (in_array($anchor, $processor->orderedMacros(), true) && ++$guard <= self::MAX_REPEAT_OCCURRENCES) {
                if ([] === $mapped) {
                    $processor->deleteRow($anchor);
                } else {
                    $processor->cloneRowAndSetValues($anchor, $mapped);
                }
            }

            return;
        }

        // Со строками, несущими картинки: cloneRow клонирует строку (индексирует макросы #i),
        // затем заливаем каждую ячейку по типу (текст — setValue, картинка — setImageValue).
        while (in_array($anchor, $processor->orderedMacros(), true) && ++$guard <= self::MAX_REPEAT_OCCURRENCES) {
            if ([] === $rows) {
                $processor->deleteRow($anchor);

                continue;
            }
            $processor->cloneRow($anchor, count($rows));
            foreach ($rows as $i => $row) {
                foreach ($subs as $sub) {
                    $this->fillRepeatCell($processor, $group.'.'.$sub.'#'.($i + 1), $row[$sub] ?? '');
                }
            }
        }
    }

    /**
     * Заливка одной ячейки повтора по индексированному токену `{{group.sub#i}}`: строка → setValue,
     * ImageValue → setImageValue (битый/отсутствующий файл → чистим ячейку, как у одиночной картинки).
     */
    private function fillRepeatCell(DocxMacroProcessor $processor, string $token, string|ImageValue $cell): void
    {
        if ($cell instanceof ImageValue) {
            if (is_readable($cell->path)) {
                $processor->setImageValue($token, $this->imageOptions($cell));
            } else {
                $processor->setValue($token, '');
            }

            return;
        }

        $processor->setValue($token, $cell);
    }

    /**
     * Повтор блоком-абзацем (cloneBlock): клонирует регион {{group}}…{{/group}} (либо {{group?}}…{{/group?}}
     * для опционального повтора) по количеству (список Word — нумерацию проставит сам), заполняет
     * `{{group.sub#i}}`. Пусто → удаляет регион. Группа может встречаться в НЕСКОЛЬКИХ местах — крутим,
     * пока маркер группы ещё есть.
     *
     * @param list<string>                           $subs
     * @param list<array<string, string|ImageValue>> $rows
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
                    $this->fillRepeatCell($processor, $group.'.'.$sub.'#'.$rowNumber, $row[$sub] ?? '');
                }
            }
        }
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
     * PhpWord-опции вставки картинки. Ключевой нюанс prepareImageAttrs: незаданному измерению PhpWord
     * подставляет СВОЙ дефолт (высота 70px), после чего fixImageWidthHeightRatio трактует картинку как
     * бокс width×70 и ужимает заданную сторону под эти 70px — картинка выходит крошечной. Лечим тем, что
     * высоту ВСЕГДА шлём пустой строкой: PhpWord посчитает её по пропорции (ветка `height === ''`), а не
     * из дефолта. Ширину задаёт: (а) явный ImageValue->width (программный вызов), либо (б) инлайн-аргумент
     * плейсхолдера в шаблоне ({{photos.image:450}}). Для (б) ширину в опциях НЕ передаём — иначе наш
     * baseValue перебил бы inline (chooseImageDimension отдаёт приоритет baseValue). Без аргумента в
     * шаблоне сработает дефолтная ширина PhpWord (~115px).
     *
     * @return array{path: string, ratio: bool, width?: int, height: int|string}
     */
    private function imageOptions(ImageValue $image): array
    {
        $options = ['path' => $image->path, 'ratio' => true, 'height' => $image->height ?? ''];

        if (null !== $image->width) {
            $options['width'] = $image->width;
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
     *     inlineRegions: list<string>
     * }
     */
    private function parse(DocxMacroProcessor $processor): array
    {
        $mainContents = $processor->orderedMacros();     // тело, по порядку — для структуры блоков
        $allTokens = $processor->getVariables();          // тело + колонтитулы — полный набор переменных

        // маркеры блоков распознаём по паре {{name}} / {{/name}} ИЛИ {{name?}} / {{/name?}} (опц. регион).
        // Значение в $closeSet = optional-флаг региона (снят с `/name?` через $closeSet[rtrim($n,'?')]).
        $closeSet = [];
        foreach ($allTokens as $token) {
            if (str_starts_with($token, '/')) {
                $name = substr($token, 1);
                $optional = str_ends_with($name, '?');
                $closeSet[$optional ? substr($name, 0, -1) : $name] = $optional;
            }
        }

        // Опциональный регион, у которого оба маркера лежат в ОДНОМ абзаце — инлайн, а не PhpWord-блок
        // (cloneBlock/deleteBlock требуют маркеры каждый в своём <w:p>, иначе рвут документ). Строгие блоки
        // (без '?') инлайн не бывают — их всегда разворачивает cloneBlock для {{group.sub}}-повтора.
        $inlineLogicals = [];
        foreach ($closeSet as $logical => $optional) {
            if ($optional && $processor->isInlineRegion($logical)) {
                $inlineLogicals[$logical] = true;
            }
        }

        // Точная литеральная форма открывающего маркера каждого блока: 'name' для строгого, 'name?' для
        // опционального. Матчим ТОЧНО (не rtrim-ом), иначе плейсхолдер {{note}} внутри блока {{note?}} с
        // тем же логическим именем ложно распознаётся как повторное открытие региона. Инлайн-регионы в
        // $blockOpens не попадают — их снимает resolveInlineOptionalRegions(), не deleteBlock/cloneBlock.
        $blockOpens = [];
        foreach ($closeSet as $logical => $optional) {
            if (isset($inlineLogicals[$logical])) {
                continue;
            }
            $blockOpens[$optional ? $logical.'?' : $logical] = $logical;
        }

        // Литералы открывающего/закрывающего маркера инлайн-региона — исключаем из flat-значений и из
        // membership реальных блоков (это структурная разметка региона, не содержимое).
        $inlineOpens = [];
        $inlineCloses = [];
        foreach (array_keys($inlineLogicals) as $logical) {
            $inlineOpens[$logical.'?'] = $logical;
            $inlineCloses['/'.$logical.'?'] = $logical;
        }

        // повторяемые группы: точечные токены {{group.sub}} (строка таблицы). Все токены группы — в одной
        // строке; anchor — любой из них (cloneRow индексирует всю строку). Из flat-значений исключаем.
        // ТОЛЬКО по телу (orderedMacros): cloneRow/cloneBlock работают по tempDocumentMainPart, повтор в
        // колонтитуле не поддержан (иначе anchor есть, а cloneRow не найдёт → PhpWord-Exception → 500).
        $repeats = [];
        $repeatMembers = [];
        foreach ($mainContents as $token) {
            // Точечное подполе {{group.sub}}; допускаем PhpWord-инлайн-аргумент размера картинки
            // ({{photos.image:450}} → :450), он не часть имени подполя — отбрасываем при опознании.
            if (1 === preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)(?::.*)?$/', $token, $m)) {
                $group = $m[1];
                $sub = $m[2];
                $repeatMembers[$token] = true;
                $repeats[$group] ??= ['subs' => [], 'anchor' => $token];
                if (!in_array($sub, $repeats[$group]['subs'], true)) {
                    $repeats[$group]['subs'][] = $sub;
                }
            }
        }

        // Рассинхрон `?` между открывающим и закрывающим маркером региона — испорченный шаблон:
        // {{note?}}…{{/note}} (или наоборот) не даёт понять, строгий это блок или опциональный регион,
        // а без проверки один из маркеров молча становится плоским значением/фантомным блоком (см. баг-репорт
        // задачи). Открывающий маркер региона ищем как ПЕРВЫЙ токен тела с тем же логическим именем — по
        // конвенции это и есть open (значения с тем же именем ВНУТРИ региона стоят дальше и не мешают);
        // инлайн-регион пропускаем — isInlineRegion() уже требует буквально {{name?}}…{{/name?}}, там
        // рассинхрон в принципе не матчится и остаётся неоткрытым регионом, не ложным совпадением.
        foreach ($closeSet as $logical => $closeOptional) {
            if (isset($inlineLogicals[$logical])) {
                continue;
            }

            foreach ($mainContents as $token) {
                if (str_starts_with($token, '/') || isset($repeatMembers[$token])) {
                    continue;
                }

                $openOptional = str_ends_with($token, '?');
                $tokenLogical = $openOptional ? substr($token, 0, -1) : $token;
                if ($tokenLogical !== $logical) {
                    continue;
                }

                if ($openOptional !== $closeOptional) {
                    throw new AppException(sprintf('Шаблон: маркеры региона «%s» рассинхронизированы по «?» — {{%1$s}}…{{/%1$s}} или {{%1$s?}}…{{/%1$s?}}.', $logical));
                }

                break; // открывающий нашёлся и совпал — по телу дальше искать нечего
            }
        }

        $values = [];
        $blocks = [];
        $stack = [];
        $inlineStack = [];
        $seenLogical = [];

        // 1) тело: значения + membership блоков в порядке появления
        foreach ($mainContents as $content) {
            if (isset($inlineCloses[$content])) {
                array_pop($inlineStack); // закрытие инлайн-региона {{/name?}}
                continue;
            }
            if (isset($inlineOpens[$content])) {
                $inlineStack[] = $inlineOpens[$content]; // открытие инлайн-региона {{name?}}
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
                // внутри инлайн-региона значение опционально (нет данных → регион вырежется целиком,
                // а не missing). Внутри любого блока — тоже опционально (миссинг для строгих блоков — след. задача).
                'optional' => $optionalMark || null !== $block || [] !== $inlineStack,
                'block' => $block,
            ];
            $seenLogical[$logical] = true;

            foreach ($stack as $enclosing) {
                $blocks[$enclosing]['values'][] = $logical;
            }
        }

        // 2) колонтитулы/сноски: плейсхолдеры вне тела — как обычные top-level значения
        //    (опциональные блоки/регионы поддерживаются только в теле документа)
        foreach ($allTokens as $token) {
            if (str_starts_with($token, '/') || isset($blockOpens[$token])
                || isset($repeatMembers[$token]) || isset($inlineOpens[$token])
                || 1 === preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $token)) {
                continue; // маркер блока/региона / член повторяемой группы / точечный синтаксис — не flat-значение
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

        return [
            'values' => $values,
            'blocks' => $blocks,
            'repeats' => $repeats,
            'inlineRegions' => array_keys($inlineLogicals),
        ];
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
