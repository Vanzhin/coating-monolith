<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Position;

use App\Personnel\Application\UseCase\Command\DeletePosition\DeletePositionCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/cabinet/personnel/position/{id}/delete',
    name: 'app_cabinet_personnel_position_delete',
    methods: ['POST'],
)]
final class DeleteAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $this->csrfGuard->assertValid('delete', $request->request->getString('_token'));
        try {
            $this->commandBus->execute(new DeletePositionCommand($id));
            $this->addFlash('position_removed_success', 'Должность удалена.');
        } catch (AppException $e) {
            $this->addFlash('position_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_personnel_position_list');
    }
}
