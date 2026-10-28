<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\FileText;

use App\Shared\Domain\FileText\FileText;
use App\Shared\Domain\FileText\FileTextExtractor;
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $logger,
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
        // Безрасширенный путь (напр. временная копия из хранилища, tempnam без суффикса) —
        // выводим формат из переданного mime. Без этого вложения, прочитанные через Shared/File,
        // не диспетчеризовались бы вовсе.
        if ('' === $extension && null !== $mimeType) {
            $extension = self::extensionForMime($mimeType);
        }

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
            // Техническую простыню (команда/exit/stderr) — в лог; инженеру — чистая категория.
            $this->logger->error('Извлечение текста из файла не удалось', ['path' => $path, 'exception' => $e]);

            return FileText::error('Не удалось прочитать файл');
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

    /** Расширение по mime — для входов без расширения в пути (временные копии из хранилища). */
    private static function extensionForMime(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/heic' => 'heic',
            default => '',
        };
    }
}
