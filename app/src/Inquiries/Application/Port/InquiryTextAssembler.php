<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Port;

use App\Inquiries\Application\DTO\AssembledText;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;

/**
 * Собирает текст запроса для извлечения: тело + текст вложений (через Shared FileTextExtractor).
 * Движок про PDF/DOCX ничего не знает — зовёт этот порт и получает готовый текст + предупреждения.
 */
interface InquiryTextAssembler
{
    public function assemble(Inquiry $inquiry): AssembledText;
}
