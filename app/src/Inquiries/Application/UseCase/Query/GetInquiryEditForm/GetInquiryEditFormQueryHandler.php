<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryEditForm;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterOptionsView;
use App\Coatings\Application\UseCase\Query\GetCoatingSystemsFilterOptions\GetCoatingSystemsFilterOptionsQuery;
use App\Inquiries\Application\DTO\View\InquiryEditFormView;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Данные для формы правки параметров: текущие распознанные значения (предзаполнение) + дерево
 * вариантов фильтра Coatings (через query-шину — домен чужой не трогаем). Доступ — только админ.
 */
final readonly class GetInquiryEditFormQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetInquiryEditFormQuery $query): ?InquiryEditFormView
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($query->inquiryId);
        if (null === $inquiry) {
            return null;
        }

        $params = $inquiry->getRecognizedParameters();
        $input = CoatingSystemsFilterInput::fromArray(null !== $params ? $params->toArray() : []);

        /** @var CoatingSystemsFilterOptionsView $options */
        $options = $this->queryBus->execute(new GetCoatingSystemsFilterOptionsQuery());

        return new InquiryEditFormView($inquiry->getId(), $input, $options);
    }
}
