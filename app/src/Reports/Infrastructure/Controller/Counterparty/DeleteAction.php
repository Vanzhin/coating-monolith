<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Counterparty;

use App\Reports\Application\UseCase\Command\DeleteCounterparty\DeleteCounterpartyCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/reports/counterparty/{id}/delete',
    name: 'app_cabinet_reports_counterparty_delete',
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
            $this->commandBus->execute(new DeleteCounterpartyCommand($id));
            $this->addFlash('counterparty_removed_success', 'Контрагент удалён.');
        } catch (\Exception|\Error $e) {
            $this->addFlash('counterparty_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_reports_counterparty_list');
    }
}
