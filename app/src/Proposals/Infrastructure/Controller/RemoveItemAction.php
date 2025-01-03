<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfoItem\RemoveGeneralProposalInfoItemCommand;
use App\Shared\Infrastructure\Bus\CommandBus;
use App\Shared\Infrastructure\Controller\BaseController;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals/{proposal_id}/item/{item_id}/delete', name: 'app_cabinet_proposals_general_proposal_item_delete')]
class RemoveItemAction extends BaseController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        LoggerInterface             $logger,
    )
    {
        parent::__construct($logger);
    }

    public function __invoke(Request $request, string $proposal_id, string $item_id): Response
    {
        try {
            $command = new RemoveGeneralProposalInfoItemCommand($item_id);
            $this->commandBus->execute($command);
            $this->addFlash('general_proposal_info_item_removed_success', 'Элемент формы удален.');

            return $this->redirectToRoute('app_cabinet_proposals_general_proposal_update', ['id' => $proposal_id]);
        } catch (\Throwable $e) {
            $this->addFlash('general_proposal_info_item_removed_error', $this->getClientErrorMessage($e));

            return $this->redirectToRoute('app_cabinet_proposals_general_proposal_update', ['id' => $proposal_id]);
        }
    }
}