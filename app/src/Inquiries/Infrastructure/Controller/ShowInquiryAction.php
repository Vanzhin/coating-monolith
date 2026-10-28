<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\InquiryView;
use App\Inquiries\Application\UseCase\Query\GetInquiry\GetInquiryQuery;
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

        return $this->render('cabinet/inquiry/show.html.twig', [
            'inquiry' => $view,
            'canEdit' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }
}
