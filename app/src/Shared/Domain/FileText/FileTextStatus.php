<?php

declare(strict_types=1);

namespace App\Shared\Domain\FileText;

enum FileTextStatus
{
    /** Текстовый слой извлечён. */
    case EXTRACTED;
    /** Файл без текстового слоя (скан/картинка) — текста нет. */
    case SCAN;
    /** Не прочитался: формат не поддержан, битый, слишком большой, таймаут, защищён. */
    case ERROR;
}
