<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;

/**
 * Процессор одного типа запроса: прогоняет его конвейер (сборка текста → кэш/модель → поиск →
 * сохранение снимка). Диспетчер выбирает процессор по supportedType(). Новый тип = новая реализация,
 * диспетчер не трогаем. Мутирует переданный агрегат; загрузку/сохранение/авторизацию делает диспетчер.
 */
interface InquiryProcessor
{
    public function supportedType(): InquiryType;

    public function process(Inquiry $inquiry): void;
}
