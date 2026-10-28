<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\ReprocessInquiry;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Application\Service\SystemSelection\SystemSelectionSearch;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Переподбор после ручной правки параметров. Синхронно: сперва поиск (общий SystemSelectionSearch —
 * те же гейты, что у движка), потом атомарно recognize(Manual) → match. Поиск ДО мутаций агрегата,
 * чтобы при сбое запрос остался в прежнем состоянии, а не завис на полпути. Инвариант перехода — в домене.
 */
final readonly class ReprocessInquiryCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private SystemSelectionSearch $search,
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

        $answer = $this->search->search($input);

        $now = new \DateTimeImmutable();
        $inquiry->recognize(new RecognizedParameters($input->toArray()), new ParameterSource(ParameterStrategy::Manual), $now);
        $inquiry->match($answer, $now);
        $inquiry->noteWarnings(...(0 === $answer->total ? ['Подбор не дал ни одной системы — проверьте параметры.'] : []));

        $this->repository->add($inquiry);
    }
}
