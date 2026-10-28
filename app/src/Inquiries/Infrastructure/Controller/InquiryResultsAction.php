<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\InquiryResultsView;
use App\Inquiries\Application\UseCase\Query\GetInquiryResults\GetInquiryResultsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Догрузка ленты подобранных систем («Показать ещё») — очередная страница ЖИВОГО прогона параметров.
 * Отдаёт только пачку карточек (общий _list_cards), как partial списка систем; canEdit=false — без
 * кебаба правок систем из контекста запроса.
 */
#[Route(path: '/cabinet/inquiry/{id}/results', name: 'app_cabinet_inquiry_results', methods: ['GET'])]
final class InquiryResultsAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $results = $this->queryBus->execute(new GetInquiryResultsQuery($id, $page));
        \assert($results instanceof InquiryResultsView);

        return $this->render('cabinet/coating/coating_system/_list_cards.html.twig', [
            'items' => $results->items,
            'canEdit' => false,
        ]);
    }
}
