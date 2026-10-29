<?php

declare(strict_types=1);

namespace App\Shared\Domain\Mail;

/**
 * Общий настраиваемый читатель почтового ящика по IMAP. Stateless: аккаунт передаётся на каждый вызов,
 * поэтому один адаптер обслуживает любой ящик (сегодня — приём запросов, завтра — другая фича). Непрочитанные
 * вычитываются БЕЗ простановки \Seen (peek); пометку ставим явно после успешной обработки.
 */
interface MailboxReader
{
    /** @return list<IncomingMessage> непрочитанные письма (без изменения флагов) */
    public function fetchUnseen(ImapAccount $account, int $limit): array;

    /** Пометить письмо прочитанным (обработанным) — по серверному uid. */
    public function markSeen(ImapAccount $account, string $uid): void;
}
