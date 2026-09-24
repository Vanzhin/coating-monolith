<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Отдельной страницы просмотра нет: отчёт открывается сразу на заполнении/редактировании
 * (при утверждении форма блокируется целиком). Канонический URL /cabinet/report/{id} ведёт туда.
 */
#[Route(path: '/cabinet/report/{id}', name: 'app_cabinet_report_view', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class ViewAction extends AbstractController
{
    public function __invoke(string $id): Response
    {
        return $this->redirectToRoute('app_cabinet_report_fill', ['id' => $id]);
    }
}
