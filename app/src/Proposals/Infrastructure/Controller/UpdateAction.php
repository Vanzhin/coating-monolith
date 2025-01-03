<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTOTransformer;
use App\Proposals\Application\UseCase\Command\UpdateGeneralProposalInfo\UpdateGeneralProposalInfoCommand;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoUnit;
use App\Proposals\Domain\Service\GeneralProposalInfoFetcher;
use App\Proposals\Infrastructure\Adapter\CoatingsAdapter;
use App\Proposals\Infrastructure\Mapper\GeneralProposalInfoMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Controller\BaseController;
use App\Shared\Infrastructure\Validation\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals/{id}/edit', name: 'app_cabinet_proposals_general_proposal_update')]
class UpdateAction extends BaseController
{
    public function __construct(
        private readonly CommandBusInterface               $commandBus,
        private readonly Validator                         $validator,
        private readonly GeneralProposalInfoFetcher        $generalProposalInfoFetcher,
        private readonly GeneralProposalInfoDTOTransformer $generalProposalInfoDTOTransformer,
        private readonly GeneralProposalInfoMapper         $generalProposalInfoMapper,
        private readonly CoatingsAdapter                   $coatingsAdapter,
        LoggerInterface                                    $logger,

    )
    {
        parent::__construct($logger);
    }

    public function __invoke(Request $request, string $id): Response
    {
        try {
            $addItem = $request->query->get('add_item') === "1";

            $coatings = $this->coatingsAdapter->getPagedCoatings();
            $data = [
                'units' => GeneralProposalInfoUnit::values(),
                'durabilities' => CoatingSystemDurability::values(),
                'categories' => CoatingSystemCorrosiveCategory::values(),
                'treatments' => CoatingSystemSurfaceTreatment::values(),
                'methods' => CoatingSystemApplicationMethod::values(),
            ];
            $proposal = $this->generalProposalInfoFetcher->getRequiredGeneralProposalInfo($id);
            if (!$proposal) {
                $this->addFlash('general_proposal_info_update_error', sprintf('Форма с идентификатором "%s" не найдена.', $id));
                return $this->redirectToRoute('app_cabinet_proposals_general_proposal_list');
            }

            $dto = $this->generalProposalInfoDTOTransformer->fromEntity($proposal);
            if ($request->isMethod(Request::METHOD_POST)) {
                $inputData = $request->getPayload()->all();
                $inputData['id'] = $id;
                $inputData['ownerId'] = $proposal->getOwnerId();
                $errors = $this->validator->validate($request->getPayload()->all(), $this->generalProposalInfoMapper->getValidationCollectionGeneralProposalInfo());
                if ($errors) {
                    throw new \Exception(current($errors)->getFullMessage());
                }
                $dto = $this->generalProposalInfoDTOTransformer->fromArray($inputData);
                $command = new UpdateGeneralProposalInfoCommand($id, $dto, true);
                $result = $this->commandBus->execute($command);
                if ($addItem) {
                    $dto = $result->dto;

                    return $this->render('cabinet/proposal/edit.html.twig', compact(array_keys(get_defined_vars())));
                }
                $this->addFlash('general_proposal_info_updated_success', sprintf('Форма "%s" обновлена.', $dto->number));

                return $this->redirectToRoute('app_cabinet_proposals_general_proposal_list');
            }

            return $this->render('cabinet/proposal/edit.html.twig', compact(array_keys(get_defined_vars())));
        } catch (\Throwable $e) {
            $error = $this->getClientErrorMessage($e);

            return $this->render('cabinet/proposal/edit.html.twig', compact(array_keys(get_defined_vars())));
        }
    }
}