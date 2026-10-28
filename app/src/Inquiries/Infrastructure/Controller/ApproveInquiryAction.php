<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\UseCase\Command\ApproveInquiry\ApproveInquiryCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/cabinet/inquiry/{id}/approve', name: 'app_cabinet_inquiry_approve', methods: ['POST'])]
final class ApproveInquiryAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('approve', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Сессия устарела, попробуйте ещё раз.');

            return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
        }

        try {
            $this->commandBus->execute(new ApproveInquiryCommand($id));
            $this->addFlash('success', 'Подбор одобрен.');
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
    }
}
