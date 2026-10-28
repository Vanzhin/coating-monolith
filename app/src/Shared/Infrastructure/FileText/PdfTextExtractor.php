<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\FileText;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * PDF → текст через системный pdftotext (poppler). `-layout` сохраняет табличную
 * раскладку опросников, `-nopgbrk` убирает разрывы страниц. Таймаут процесса —
 * ограничитель от зависания на битом PDF; ненулевой код (в т.ч. пароль-защита) →
 * исключение, которое диспетчер переведёт в ERROR. Пустой вывод (скан без текста)
 * диспетчер по emptyMeansScan() переведёт в SCAN.
 */
final readonly class PdfTextExtractor implements FormatTextExtractor
{
    public function __construct(private int $timeoutSec)
    {
    }

    public function extensions(): array
    {
        return ['pdf'];
    }

    public function extract(string $path): string
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', '-nopgbrk', $path, '-']);
        $process->setTimeout((float) $this->timeoutSec);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    public function emptyMeansScan(): bool
    {
        return true;
    }
}
