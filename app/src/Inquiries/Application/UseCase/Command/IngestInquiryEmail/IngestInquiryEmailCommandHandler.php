<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\IngestInquiryEmail;

use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Factory\InquiryFactory;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Application\UseCase\Query\GetUserIdByEmail\GetUserIdByEmailQuery;

/**
 * Заводит запрос из письма. Отправитель резолвится в пользователя через шину (кросс-контекст Users);
 * нет такого пользователя → не заводим (адаптер отобьёт письмом). Есть → запрос Received, владелец =
 * пользователь, дальше доменное событие InquirySubmitted запускает async-конвейер (как CreateInquiry).
 * Актор — система (CLI-поллер), проходит capability-гейт.
 */
final readonly class IngestInquiryEmailCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private QueryBusInterface $queryBus,
        private InquiryFactory $factory,
        private InquiryRepositoryInterface $repository,
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

        $inquiry = $this->factory->create(
            InquiryType::SystemSelection,
            $ownerUlid,
            $this->composeText($command->subject, $command->body),
            new StringCollection(...$command->attachmentFileIds),
            new \DateTimeImmutable(),
        );
        $this->repository->add($inquiry);

        return IngestInquiryEmailResult::created($inquiry->getId());
    }

    /** Тема + тело в один текст запроса; тема помогает распознаванию. */
    private function composeText(string $subject, string $body): string
    {
        return trim(trim($subject)."\n\n".trim($body));
    }
}
