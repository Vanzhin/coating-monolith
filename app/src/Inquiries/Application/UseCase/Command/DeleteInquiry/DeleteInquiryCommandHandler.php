<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\DeleteInquiry;

use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Удаляет запрос независимо от статуса. Авторизация — capability (админ/система).
 */
final readonly class DeleteInquiryCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(DeleteInquiryCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($command->inquiryId);
        if (null === $inquiry) {
            throw new AppException('Запрос не найден.', Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($inquiry);
    }
}
