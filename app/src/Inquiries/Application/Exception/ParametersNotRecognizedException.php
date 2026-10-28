<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Exception;

/**
 * Модель отработала, но извлечённые параметры пусты — ни одной оси фильтра. Поиск-по-всему
 * вернул бы весь каталог, это не подбор. Запрос в Failed; инженер вбивает параметры вручную
 * (Failed → Recognized) и переподбирает.
 */
final class ParametersNotRecognizedException extends InquiryProcessingException
{
    public function __construct()
    {
        parent::__construct('Параметры подбора не распознаны (ни одной оси фильтра) — нужен ручной ввод инженером.');
    }
}
