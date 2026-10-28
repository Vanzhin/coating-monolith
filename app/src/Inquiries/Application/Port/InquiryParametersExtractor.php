<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Port;

use App\Inquiries\Application\DTO\ExtractionResult;
use App\Inquiries\Application\DTO\InquiryText;

/**
 * Извлекает параметры подбора из готового текста запроса. Говорит на языке задачи
 * (текст → параметры), не на языке модели: сегодня за портом LLM (NetAngels), завтра
 * перед ним встанут кэш/шаблоны (без модели) — потребители (движок) не меняются.
 */
interface InquiryParametersExtractor
{
    public function extract(InquiryText $text): ExtractionResult;
}
