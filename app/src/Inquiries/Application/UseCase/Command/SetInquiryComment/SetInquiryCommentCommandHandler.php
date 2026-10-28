<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\SetInquiryComment;

use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сохраняет заметку инженера по запросу. Доступ — только админ. Заметка редактируется в любой момент
 * (инвариант «в любой момент» — в домене: setComment без проверки статуса).
 */
final readonly class SetInquiryCommentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(SetInquiryCommentCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($command->inquiryId);
        if (null === $inquiry) {
            throw new AppException('Запрос не найден.', Response::HTTP_NOT_FOUND);
        }

        $inquiry->setComment($command->comment, new \DateTimeImmutable());
        $this->repository->add($inquiry);
    }
}
