<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfo\RemoveGeneralProposalInfoCommand;
use App\Shared\Infrastructure\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals/{id}/delete', name: 'app_cabinet_proposals_general_proposal_delete')]
class DeleteAction extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    )
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $command = new RemoveGeneralProposalInfoCommand($id);
        $this->commandBus->execute($command);
        $this->addFlash('general_proposal_info_removed_success', 'Форма удалена.');

        return $this->redirectToRoute('app_cabinet_proposals_general_proposal_list');
    }
}