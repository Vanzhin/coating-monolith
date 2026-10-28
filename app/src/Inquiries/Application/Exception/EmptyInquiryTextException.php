<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Exception;

/**
 * Сборка текста дала пустоту: тела нет, вложения не прочитались (скан/битый/формат). Извлекать
 * параметры не из чего — модель не зовём, запрос в Failed, инженер разбирается с вложениями.
 */
final class EmptyInquiryTextException extends InquiryProcessingException
{
    public function __construct()
    {
        parent::__construct('Пустой текст запроса: тела нет и вложения не прочитались — распознавать нечего.');
    }
}
