<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Подписанный акт выдачи (история) в детали человека: id документа (для скачивания скана) + дата подписи. */
final class IssuanceActDTO
{
    public function __construct(
        public string $documentId,
        public ?string $signedAt,
    ) {
    }
}
