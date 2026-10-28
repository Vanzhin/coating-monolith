<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\RetryInquiry;

use App\Shared\Application\Command\Command;

/**
 * Ручной повторный прогон конвейера: застрявший «Получен» (воркер упал) или «Ошибка». Весь путь
 * (сборка текста → параметры → подбор) переигрывает воркер асинхронно — команде нужен только id.
 */
final readonly class RetryInquiryCommand extends Command
{
    public function __construct(public string $inquiryId)
    {
    }
}
