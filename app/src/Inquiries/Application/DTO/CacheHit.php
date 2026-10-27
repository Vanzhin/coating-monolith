<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;

/**
 * Попадание в кэш: готовые одобренные параметры похожего запроса + id источника (для провенанса
 * ParameterStrategy::Cache). Как нашли (отпечаток/эмбеддинг) — забота реализации кэша, не движка.
 */
final readonly class CacheHit
{
    public function __construct(public RecognizedParameters $parameters, public ?string $sourceInquiryId = null)
    {
    }
}
