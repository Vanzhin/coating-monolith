<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\UseCase\Command\ReprocessInquiry\ReprocessInquiryCommand;
use App\Inquiries\Infrastructure\Mapper\InquiryReprocessParametersMapper;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Инженер поправил параметры → синхронный переподбор. Тонкий: собрать параметры маппером, отдать
 * команде, вернуться на карточку с результатом/ошибкой.
 */
#[Route(path: '/cabinet/inquiry/{id}/reprocess', name: 'app_cabinet_inquiry_reprocess', methods: ['POST'])]
final class ReprocessInquiryAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly InquiryReprocessParametersMapper $mapper,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reprocess', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Сессия устарела, попробуйте ещё раз.');

            return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
        }

        try {
            $this->commandBus->execute(new ReprocessInquiryCommand($id, $this->mapper->parametersFromRequest($request)));
            $this->addFlash('success', 'Параметры обновлены, подбор выполнен заново.');
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
    }
}
