<?php

declare(strict_types=1);

namespace App\Shared\Domain\FileText;

/**
 * Механически извлекает текстовый слой из файла (PDF/DOCX/XLSX). Без модели и
 * осмысления. Единый вход; реализация сама выбирает парсер по типу, применяет
 * ограничители и возвращает FileText со статусом. Никогда не бросает на кривом/
 * недоверенном файле — ошибки превращаются в FileTextStatus::ERROR.
 */
interface FileTextExtractor
{
    public function extract(string $path, ?string $mimeType = null): FileText;
}
