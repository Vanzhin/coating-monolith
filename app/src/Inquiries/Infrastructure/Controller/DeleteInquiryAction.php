<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\UseCase\Command\DeleteInquiry\DeleteInquiryCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/cabinet/inquiry/{id}/delete', name: 'app_cabinet_inquiry_delete', methods: ['POST'])]
final class DeleteInquiryAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id, Request $request): Response
    {
        // Токен 'delete' — тот же, что ставит переиспользуемая #deleteModal.
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Сессия устарела, попробуйте ещё раз.');

            return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
        }

        try {
            $this->commandBus->execute(new DeleteInquiryCommand($id));
            $this->addFlash('success', 'Запрос удалён.');
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
        }

        return $this->redirectToRoute('app_cabinet_inquiry_new');
    }
}
