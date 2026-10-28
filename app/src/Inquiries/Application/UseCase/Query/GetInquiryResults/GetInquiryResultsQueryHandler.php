<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryResults;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Inquiries\Application\DTO\View\InquiryResultsView;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Подобранные системы = ЖИВОЙ прогон распознанных параметров по каталогу Coatings (через query-шину).
 * Ничего не морозим: поменялись слои/стойкость/соответствие — список отражает текущую реальность.
 * Постранично (PER_PAGE), как список систем. Доступ — только админ. Нет параметров → пусто.
 */
final readonly class GetInquiryResultsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetInquiryResultsQuery $query): InquiryResultsView
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $page = max(1, $query->page);

        $inquiry = $this->repository->findOneById($query->inquiryId);
        $params = $inquiry?->getRecognizedParameters();
        if (null === $params) {
            return new InquiryResultsView([], 0, $page);
        }

        $arr = $params->toArray();
        $arr['page'] = $page;
        $arr['limit'] = InquiryResultsView::PER_PAGE;

        /** @var SearchCoatingSystemsQueryResult $result */
        $result = $this->queryBus->execute(SearchCoatingSystemsQuery::fromParameters(CoatingSystemsFilterInput::fromArray($arr)));

        return new InquiryResultsView($result->items, $result->total, $page);
    }
}
