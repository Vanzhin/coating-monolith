<?php

declare(strict_types=1);

namespace App\Shared\Domain\Mail;

/** Вложение входящего письма: имя, содержимое (байты) и MIME (если известен). */
final readonly class IncomingAttachment
{
    public function __construct(
        public string $name,
        public string $content,
        public ?string $mime,
    ) {
    }
}
