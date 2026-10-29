<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Domain\Mail\ImapAccount;
use App\Shared\Domain\Mail\IncomingAttachment;
use App\Shared\Domain\Mail\IncomingMessage;
use App\Shared\Domain\Mail\MailboxReader;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Читатель ящика на webklex/php-imap (чистый PHP, ext-imap не нужен). Общий и настраиваемый: аккаунт
 * приходит параметром, адаптер обслуживает любой ящик. Непрочитанные тянем через leaveUnread() (peek —
 * \Seen не ставим на вычитке); пометку ставим явно в markSeen() после успешной обработки письма.
 */
final class WebklexMailboxReader implements MailboxReader
{
    public function fetchUnseen(ImapAccount $account, int $limit): array
    {
        $client = $this->connect($account);
        try {
            $folder = $client->getFolder($account->folder);
            if (null === $folder) {
                return [];
            }

            $out = [];
            foreach ($folder->query()->unseen()->leaveUnread()->limit($limit)->get() as $message) {
                \assert($message instanceof Message);
                $out[] = new IncomingMessage(
                    (string) $message->getUid(),
                    $this->fromEmail($message),
                    trim((string) $message->getSubject()),
                    trim((string) $message->getTextBody()),
                    $this->attachments($message),
                );
            }

            return $out;
        } finally {
            $client->disconnect();
        }
    }

    public function markSeen(ImapAccount $account, string $uid): void
    {
        $client = $this->connect($account);
        try {
            $folder = $client->getFolder($account->folder);
            // Только флипаем флаг — тело не парсим (вложения тянутся лениво из тела, так что и они не грузятся).
            $query = $folder?->query()->leaveUnread()->setFetchBody(false);
            $query?->getMessageByUid((int) $uid)?->setFlag('Seen');
        } finally {
            $client->disconnect();
        }
    }

    private function connect(ImapAccount $account): Client
    {
        $client = (new ClientManager())->make([
            'host' => $account->host,
            'port' => $account->port,
            'encryption' => '' !== $account->encryption ? $account->encryption : false,
            'validate_cert' => true,
            'username' => $account->username,
            'password' => $account->password,
            'protocol' => 'imap',
        ]);
        $client->connect();

        return $client;
    }

    private function fromEmail(Message $message): string
    {
        $first = $message->getFrom()[0] ?? null;

        return null !== $first ? (string) $first->mail : '';
    }

    /** @return list<IncomingAttachment> */
    private function attachments(Message $message): array
    {
        $out = [];
        foreach ($message->getAttachments() as $attachment) {
            $out[] = new IncomingAttachment(
                trim((string) $attachment->getName()) ?: 'attachment',
                (string) $attachment->getContent(),
                $attachment->getMimeType() ?: null,
            );
        }

        return $out;
    }
}
