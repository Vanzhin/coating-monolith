<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RenderedDocument;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Domain\Templating\TemplateRenderer;
use App\Shared\Domain\Templating\TemplateVariable;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\Templating\ValidationResult;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Драйвер docx-шаблонов на PhpWord. Возможности: text + image + optional block.
 *
 * Опциональность:
 *  - плейсхолдер {{name?}} — опциональный «на месте» (нет данных → чистим);
 *  - блок {{opt_x}} … {{/opt_x}} — регион удаляется целиком, если данных внутри нет (presence-driven,
 *    all-or-none). Маркеры блока стоят каждый в своём абзаце.
 */
final class DocxTemplateRenderer implements TemplateRenderer
{
    private const DEFAULT_MAX_IMAGE_WIDTH_PX = 600;

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
            if ('partial' === $state) {
                $invalidBlocks[] = $name;
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
            if ('keep' === $state) {
                $processor->cloneBlock($name, 1);
            } else {
                $processor->deleteBlock($name);
            }
        }

        foreach ($parsed['values'] as $value) {
            $block = $value['block'];
            if (null !== $block && 'keep' !== ($states[$block] ?? 'delete')) {
                continue; // блок удалён — токен уже вырезан
            }
            $this->fill($processor, $value['token'], $value['logical'], $data);
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
     *     blocks: array<string, list<string>>
     * }
     */
    private function parse(DocxMacroProcessor $processor): array
    {
        $mainContents = $processor->orderedMacros();     // тело, по порядку — для структуры блоков
        $allTokens = $processor->getVariables();          // тело + колонтитулы — полный набор переменных

        // маркеры блоков распознаём по паре {{name}} / {{/name}}
        $closeSet = [];
        foreach ($allTokens as $token) {
            if (str_starts_with($token, '/')) {
                $closeSet[substr($token, 1)] = true;
            }
        }

        $values = [];
        $blocks = [];
        $stack = [];
        $seenLogical = [];

        // 1) тело: значения + membership блоков в порядке появления
        foreach ($mainContents as $content) {
            if (str_starts_with($content, '/')) {
                array_pop($stack);
                continue;
            }
            if (isset($closeSet[$content])) {
                $stack[] = $content;
                $blocks[$content] ??= [];
                continue;
            }

            $optionalMark = str_ends_with($content, '?');
            $logical = $optionalMark ? substr($content, 0, -1) : $content;
            $block = [] === $stack ? null : $stack[array_key_last($stack)];

            $values[] = [
                'logical' => $logical,
                'token' => $content,
                'optional' => $optionalMark || null !== $block,
                'block' => $block,
            ];
            $seenLogical[$logical] = true;

            foreach ($stack as $enclosing) {
                $blocks[$enclosing][] = $logical;
            }
        }

        // 2) колонтитулы/сноски: плейсхолдеры вне тела — как обычные top-level значения
        //    (опциональные блоки поддерживаются только в теле документа)
        foreach ($allTokens as $token) {
            if (str_starts_with($token, '/') || isset($closeSet[$token])) {
                continue; // маркер блока — не значение
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

        return ['values' => $values, 'blocks' => $blocks];
    }

    /**
     * @param array<string, list<string>> $blocks
     *
     * @return array<string, 'keep'|'delete'|'partial'>
     */
    private function blockStates(array $blocks, RenderData $data): array
    {
        $states = [];
        foreach ($blocks as $name => $members) {
            $unique = array_values(array_unique($members));
            $present = 0;
            foreach ($unique as $member) {
                if ($data->has($member)) {
                    ++$present;
                }
            }

            if (0 === $present) {
                $states[$name] = 'delete';
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
            return new DocxMacroProcessor($template->path);
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
