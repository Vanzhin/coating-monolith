<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Вложение для экрана: id (ссылка на Shared/File) + человекочитаемое имя. Текст для показа
 * тянется лениво отдельным эндпоинтом (не грузим в карточку).
 */
final readonly class InquiryAttachmentView
{
    public function __construct(public string $id, public string $name)
    {
    }
}
