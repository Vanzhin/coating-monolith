<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\UseCase\Query\GetExtractionAccuracy\GetExtractionAccuracyQuery;
use App\Inquiries\Application\UseCase\Query\GetPagedInquiries\GetPagedInquiriesQuery;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Inquiries\Domain\Repository\InquiriesSort;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

/**
 * Очередь запросов: поиск (ILIKE по телу) + фасеты (статус/автор) + сортировка по дате + «загрузить
 * ещё» (partial=1 отдаёт только пачку карточек). Тонкий: собрать фильтр, диспатчить, отрисовать.
 */
#[Route(path: '/cabinet/inquiry', name: 'app_cabinet_inquiry_list', methods: ['GET'])]
final class ListInquiriesAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly QueryParams $queryParams,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $search = trim((string) $request->query->get('search', '')) ?: null;
        $status = InquiryStatus::tryFrom((string) $request->query->get('status', ''));
        $sortRaw = $request->query->get('sort');
        $sort = (is_string($sortRaw) ? InquiriesSort::tryFrom($sortRaw) : null) ?? InquiriesSort::DEFAULT;
        // автор — ULID (User.id); битые id тихо отсеиваем.
        $authorIds = $this->queryParams->stringCollection($request, 'authorIds', [Ulid::class, 'isValid'], unique: true);

        $filter = new InquiriesFilter(
            pager: Pager::fromPage($page, 20),
            status: $status,
            authorIds: $authorIds,
            search: $search,
            sort: $sort,
        );
        $result = $this->queryBus->execute(new GetPagedInquiriesQuery($filter));

        if ($request->query->getBoolean('partial')) {
            return $this->render('cabinet/inquiry/_inquiry_cards_batch.html.twig', [
                'items' => $result->items,
                'canEdit' => $this->isGranted('ROLE_ADMIN'),
            ]);
        }

        return $this->render('cabinet/inquiry/list.html.twig', [
            'result' => $result,
            'accuracy' => $this->queryBus->execute(new GetExtractionAccuracyQuery()),
            'search' => $search,
            'status' => $status?->value,
            'statuses' => InquiryStatus::cases(),
            'sort' => $sort,
            'sortOptions' => InquiriesSort::cases(),
            'authorIds' => $authorIds->getList(),
        ]);
    }
}
