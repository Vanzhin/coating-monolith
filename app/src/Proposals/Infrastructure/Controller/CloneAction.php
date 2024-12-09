<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTOTransformer;
use App\Proposals\Application\UseCase\Command\CreateGeneralProposalInfo\CreateGeneralProposalInfoCommand;
use App\Proposals\Domain\Service\GeneralProposalInfoFetcher;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals/{id}/clone', name: 'app_cabinet_proposals_general_proposal_clone')]
class CloneAction extends AbstractController
{
    private const PREFIX = 'Копия-';

    public function __construct(
        private readonly CommandBusInterface               $commandBus,
        private readonly GeneralProposalInfoFetcher        $generalProposalInfoFetcher,
        private readonly GeneralProposalInfoDTOTransformer $generalProposalInfoDTOTransformer,
    )
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        try {

            $proposal = $this->generalProposalInfoFetcher->getRequiredGeneralProposalInfo($id);
            if (!$proposal) {
                $this->addFlash('general_proposal_info_update_error', sprintf('Форма с идентификатором "%s" не найдена.', $id));
                return $this->redirectToRoute('app_cabinet_proposals_general_proposal_list');
            }
            $dto = $this->generalProposalInfoDTOTransformer->fromEntity($proposal);
            $dto->number = self::PREFIX . $dto->number . '-' . random_int(10, 9999);
            $command = new CreateGeneralProposalInfoCommand($dto);
            $result = $this->commandBus->execute($command);

            $this->addFlash('general_proposal_info_created_success', sprintf('Форма "%s" добавлена.', $dto->number));

            return $this->redirectToRoute('app_cabinet_proposals_general_proposal_update', ['id' => $result->id]);
        } catch (\Exception|\Error $e) {
            $error = $e->getMessage();
            $this->addFlash('general_proposal_info_created_error', $error);

            return $this->redirectToRoute('app_cabinet_proposals_general_proposal_list');
        }
    }
}