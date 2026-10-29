<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\CreateInquiry;

use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Factory\InquiryFactory;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Заводит запрос (статус Received) от имени текущего актора. Сохранение на flush раздаёт доменное
 * событие InquirySubmitted → воркер подхватит обработку. Авторизация — capability (админ/система).
 */
final readonly class CreateInquiryCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryFactory $factory,
        private InquiryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(CreateInquiryCommand $command): CreateInquiryCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->factory->create(
            $command->type,
            $command->ownerId ?? $this->access->currentUserId(), // переданный владелец (почта) приоритетнее актора
            $command->rawText,
            new StringCollection(...$command->attachmentFileIds),
            new \DateTimeImmutable(),
        );
        $this->repository->add($inquiry);

        return new CreateInquiryCommandResult($inquiry->getId());
    }
}
