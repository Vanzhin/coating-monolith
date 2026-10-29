<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Данные события «запрос обработан» для рендера текста (примитивы — Notifications не знает enum Inquiries). */
interface InquiryProcessedData
{
    public function inquiryId(): string;

    public function isFailed(): bool;

    public function failureReason(): ?string;
}
