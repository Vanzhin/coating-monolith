<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\UseCase\Command\SetInquiryComment\SetInquiryCommentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Сохранить заметку инженера по запросу. Тонкий: CSRF → команда → назад на карточку.
 */
#[Route(path: '/cabinet/inquiry/{id}/comment', name: 'app_cabinet_inquiry_comment', methods: ['POST'])]
final class SetInquiryCommentAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('comment', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Сессия устарела, попробуйте ещё раз.');

            return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
        }

        try {
            $this->commandBus->execute(new SetInquiryCommentCommand($id, (string) $request->request->get('comment', '')));
            $this->addFlash('success', 'Комментарий сохранён.');
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $id]);
    }
}
