<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\InquiryStatusView;
use App\Inquiries\Application\UseCase\Query\GetInquiryStatus\GetInquiryStatusQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Лёгкий JSON-статус для поллинга карточки: {status, processing}. Пока processing=true — фронт
 * опрашивает, по завершении перезагружает карточку.
 */
#[Route(path: '/cabinet/inquiry/{id}/status', name: 'app_cabinet_inquiry_status', methods: ['GET'])]
final class InquiryStatusAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        $view = $this->queryBus->execute(new GetInquiryStatusQuery($id));
        if (!$view instanceof InquiryStatusView) {
            throw $this->createNotFoundException('Запрос не найден.');
        }

        return new JsonResponse(['status' => $view->status, 'processing' => $view->processing]);
    }
}
