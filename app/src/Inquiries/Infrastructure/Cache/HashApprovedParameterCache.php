<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Cache;

use App\Inquiries\Application\DTO\CacheHit;
use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Application\Service\ContentFingerprinter;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;

/**
 * Кэш точного дубликата: по отпечатку собранного входа находит распознанного/одобренного близнеца и
 * отдаёт его параметры — процессор копирует их (ParameterStrategy::Cache), модель не зовёт. Одинаковые
 * байты → гарантированно тот же ответ (точное совпадение, не похожесть — без эмбеддингов). Отпечаток
 * близнецу ставит процессор при распознавании, поэтому index() здесь — no-op.
 */
final readonly class HashApprovedParameterCache implements ApprovedParameterCache
{
    public function __construct(
        private ContentFingerprinter $fingerprinter,
        private InquiryRepositoryInterface $inquiries,
    ) {
    }

    public function lookup(InquiryType $type, string $text): ?CacheHit
    {
        $twin = $this->inquiries->findRecognizedByContentHash($this->fingerprinter->hash($text), $type);
        $params = $twin?->getRecognizedParameters();
        if (null === $twin || null === $params) {
            return null;
        }

        return new CacheHit($params, $twin->getId());
    }

    public function index(Inquiry $inquiry): void
    {
        // Отпечаток пишет процессор при распознавании (setContentHash) — отдельная индексация не нужна.
    }
}
