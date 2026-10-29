<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Event;

/** Исход машинной обработки запроса: распознан или провален. */
enum InquiryProcessingOutcome: string
{
    case Recognized = 'recognized';
    case Failed = 'failed';
}
