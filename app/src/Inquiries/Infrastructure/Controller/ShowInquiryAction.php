<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\InquiryView;
use App\Inquiries\Application\UseCase\Query\GetInquiry\GetInquiryQuery;
use App\Inquiries\Application\UseCase\Query\GetInquiryResults\GetInquiryResultsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/cabinet/inquiry/{id}', name: 'app_cabinet_inquiry_show', methods: ['GET'])]
final class ShowInquiryAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        $view = $this->queryBus->execute(new GetInquiryQuery($id));
        if (!$view instanceof InquiryView) {
            throw $this->createNotFoundException('Запрос не найден.');
        }

        // Первая страница подобранных систем — ЖИВОЙ прогон параметров. Только для распознанных/
        // одобренных (у принятого параметров ещё нет, у проваленного — провал). Дальше догрузка — infinite_list.
        $results = in_array($view->statusValue, ['recognized', 'approved'], true)
            ? $this->queryBus->execute(new GetInquiryResultsQuery($id, 1))
            : null;

        return $this->render('cabinet/inquiry/show.html.twig', [
            'inquiry' => $view,
            'results' => $results,
            'canEdit' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }
}
