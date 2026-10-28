<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\ReprocessInquiry;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Инженер поправил параметры руками → перераспознавание (strategy Manual). Поиск систем тут НЕ гоняем:
 * подобранные системы — живой результат этих параметров, пересчитывается на показе. Проверяем лишь, что
 * задана хотя бы одна ось (иначе подбор-по-всему). Инвариант перехода статуса — в домене.
 */
final readonly class ReprocessInquiryCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(ReprocessInquiryCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($command->inquiryId);
        if (null === $inquiry) {
            throw new AppException('Запрос не найден.', Response::HTTP_NOT_FOUND);
        }

        $input = CoatingSystemsFilterInput::fromArray($command->parameters);
        if (!$input->hasAnyConstraint()) {
            throw new AppException('Укажите хотя бы один параметр подбора.');
        }

        $inquiry->recognize(
            new RecognizedParameters($input->toArray()),
            new ParameterSource(ParameterStrategy::Manual),
            new \DateTimeImmutable(),
        );
        $this->repository->add($inquiry);
    }
}
