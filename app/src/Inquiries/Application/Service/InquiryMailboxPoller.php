<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service;

use App\Inquiries\Application\UseCase\Command\IngestInquiryEmail\IngestInquiryEmailCommand;
use App\Inquiries\Application\UseCase\Command\IngestInquiryEmail\IngestInquiryEmailResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\File\StageFiles\StageFilesCommand;
use App\Shared\Application\File\StageFiles\StageFilesCommandResult;
use App\Shared\Domain\Mail\ImapAccount;
use App\Shared\Domain\Mail\IncomingMessage;
use App\Shared\Domain\Mail\MailboxReader;
use App\Shared\Infrastructure\Exception\AppException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Опрос ящика подбора: непрочитанные письма → IngestInquiryEmailCommand. Отправитель не пользователь (или
 * без прав) → просто пропускаем, запрос не заводим и НЕ отвечаем письмом. \Seen ставим ТОЛЬКО после успеха —
 * сбой переигрывается в следующий проход. Один проход и для консоли, и для Scheduler. Актор — система.
 */
final readonly class InquiryMailboxPoller
{
    public function __construct(
        private MailboxReader $reader,
        private ImapAccount $account,
        private CommandBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{configured: bool, processed: int, created: int, skipped: int}
     */
    public function poll(int $limit): array
    {
        if (!$this->account->isConfigured()) {
            return ['configured' => false, 'processed' => 0, 'created' => 0, 'skipped' => 0];
        }

        $messages = $this->reader->fetchUnseen($this->account, max(1, $limit));
        $created = 0;
        $skipped = 0;
        foreach ($messages as $message) {
            try {
                $this->handle($message, $created, $skipped);
                $this->reader->markSeen($this->account, $message->uid); // только после успешной обработки
            } catch (\Throwable $e) {
                // не помечаем \Seen — переигрывается в следующий проход
                $this->logger->error('Письмо из ящика подбора не обработано', ['uid' => $message->uid, 'error' => $e->getMessage()]);
            }
        }

        return ['configured' => true, 'processed' => \count($messages), 'created' => $created, 'skipped' => $skipped];
    }

    private function handle(IncomingMessage $message, int &$created, int &$skipped): void
    {
        $result = $this->commandBus->execute(new IngestInquiryEmailCommand(
            $message->fromEmail,
            $message->subject,
            $message->body,
            $this->stageAttachments($message),
        ));
        \assert($result instanceof IngestInquiryEmailResult);

        // Отправитель не пользователь (или без прав) → тихо пропускаем, не отвечаем.
        $result->skipped ? ++$skipped : ++$created;
    }

    /**
     * Вложения письма → во временную зону хранилища через общую StageFilesCommand (её guard: allow-list mime
     * + лимит размера). Недопустимое вложение пропускаем именно его (лог), письмо обрабатываем дальше — одно
     * письмо не должно падать из-за одной картинки-переростка. Временный файл чистим.
     *
     * @return list<string>
     */
    private function stageAttachments(IncomingMessage $message): array
    {
        $ids = [];
        foreach ($message->attachments as $attachment) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'inqmail_');
            file_put_contents($tmp, $attachment->content);
            try {
                $staged = $this->commandBus->execute(new StageFilesCommand(
                    'system',
                    [new UploadedFile($tmp, $attachment->name, $attachment->mime, null, true)],
                ));
                \assert($staged instanceof StageFilesCommandResult);
                foreach ($staged->files as $view) {
                    $ids[] = $view->uuid;
                }
            } catch (AppException $e) {
                $this->logger->warning('Вложение письма пропущено (тип/размер)', [
                    'uid' => $message->uid, 'name' => $attachment->name, 'reason' => $e->getMessage(),
                ]);
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $ids;
    }
}
