<?php

declare(strict_types=1);

namespace App\Shared\Domain\Mail;

/** Настройки подключения к почтовому ящику по IMAP (хост/порт/шифрование/аккаунт/папка). */
final readonly class ImapAccount
{
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        public string $password,
        public string $folder = 'INBOX',
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->host && '' !== $this->username;
    }
}
