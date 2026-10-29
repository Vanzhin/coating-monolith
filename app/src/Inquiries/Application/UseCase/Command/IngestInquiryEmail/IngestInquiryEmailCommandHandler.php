<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\IngestInquiryEmail;

use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommand;
use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommandResult;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Application\UseCase\Query\GetUserIdByEmail\GetUserIdByEmailQuery;

/**
 * Приём письма как запроса: единственная его забота — резолв отправителя в пользователя (через шину).
 * Нашёл → делегирует создание общей CreateInquiryCommand с ownerId = отправитель (создание/гейт/событие
 * конвейера — там, не дублируем). Не нашёл (не пользователь / нет прав) → skipped, молча, без ответа.
 */
final readonly class IngestInquiryEmailCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private QueryBusInterface $queryBus,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(IngestInquiryEmailCommand $command): IngestInquiryEmailResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $ownerUlid = $this->queryBus->execute(new GetUserIdByEmailQuery($command->fromEmail));
        if (!\is_string($ownerUlid)) {
            return IngestInquiryEmailResult::skipped(); // отправитель не пользователь — просто выходим, не отвечаем
        }

        $result = $this->commandBus->execute(new CreateInquiryCommand(
            InquiryType::SystemSelection,
            $this->composeText($command->subject, $command->body),
            $command->attachmentFileIds,
            $ownerUlid,
        ));
        \assert($result instanceof CreateInquiryCommandResult);

        return IngestInquiryEmailResult::created($result->id);
    }

    /** Тема + тело в один текст запроса; тема помогает распознаванию. */
    private function composeText(string $subject, string $body): string
    {
        return trim(trim($subject)."\n\n".trim($body));
    }
}
