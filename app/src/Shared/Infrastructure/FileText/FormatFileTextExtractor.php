<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\FileText;

use App\Shared\Domain\FileText\FileText;
use App\Shared\Domain\FileText\FileTextExtractor;

/**
 * Диспетчер: по расширению выбирает адаптер формата и применяет ограничители (размер
 * входа, нормализация + cap выходного текста, порог «пусто → скан»). Картинки — сразу
 * SCAN (не парсим). Любой сбой чтения (битый/защищён/таймаут/нет бинаря) — ERROR, не
 * исключение: вход недоверенный, сервис не должен падать.
 */
final readonly class FormatFileTextExtractor implements FileTextExtractor
{
    /** Растровые форматы — текстового слоя нет, помечаем сканом без попытки разбора. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'gif', 'heic', 'webp'];

    /** @param iterable<FormatTextExtractor> $extractors */
    public function __construct(
        private iterable $extractors,
        private int $maxFileBytes,
        private int $maxTextChars,
        private int $scanMinChars,
    ) {
    }

    public function extract(string $path, ?string $mimeType = null): FileText
    {
        if (!is_file($path)) {
            return FileText::error('Файл не найден');
        }

        $size = filesize($path);
        if (false === $size || $size > $this->maxFileBytes) {
            return FileText::error(sprintf('Файл слишком большой (> %d байт)', $this->maxFileBytes));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (\in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return FileText::scan();
        }

        $extractor = $this->pick($extension);
        if (null === $extractor) {
            return FileText::error(sprintf('Формат не поддержан: .%s', $extension));
        }

        try {
            $raw = $extractor->extract($path);
        } catch (\Throwable $e) {
            return FileText::error('Не удалось прочитать файл: '.$e->getMessage());
        }

        $normalized = TextNormalizer::normalize($raw, $this->maxTextChars);

        if ($extractor->emptyMeansScan()
            && TextNormalizer::nonWhitespaceLength($normalized['text']) < $this->scanMinChars) {
            return FileText::scan();
        }

        return FileText::extracted($normalized['text'], $normalized['truncated']);
    }

    private function pick(string $extension): ?FormatTextExtractor
    {
        foreach ($this->extractors as $extractor) {
            if (\in_array($extension, $extractor->extensions(), true)) {
                return $extractor;
            }
        }

        return null;
    }
}
