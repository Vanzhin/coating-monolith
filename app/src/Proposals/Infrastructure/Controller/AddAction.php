<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Proposals\Application\UseCase\Command\CreateGeneralProposalInfo\CreateGeneralProposalInfoCommand;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoUnit;
use App\Proposals\Infrastructure\Adapter\CoatingsAdapter;
use App\Proposals\Infrastructure\Mapper\GeneralProposalInfoMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Controller\BaseController;
use App\Shared\Infrastructure\Validation\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals', name: 'app_cabinet_proposals_general_proposal')]
class AddAction extends BaseController
{
    public function __construct(
        private readonly CommandBusInterface       $commandBus,
        private readonly Validator                 $validator,
        private readonly GeneralProposalInfoMapper $generalProposalInfoMapper,
        private readonly CoatingsAdapter           $coatingsAdapter,
        LoggerInterface                            $logger)
    {
        parent::__construct($logger);
    }

    public function __invoke(Request $request): Response
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
            if ($request->isMethod(Request::METHOD_POST)) {

                $inputData = $request->getPayload()->all();
                $inputData['ownerId'] = $this->getUser()->getUlid();
                $errors = $this->validator->validate($inputData,
                    $this->generalProposalInfoMapper->getValidationCollectionGeneralProposalInfo());
                if ($errors) {
                    throw new \Exception(current($errors)->getFullMessage());
                }
                $dto = $this->generalProposalInfoMapper->buildDtoFromInputData($inputData);
                $command = new CreateGeneralProposalInfoCommand($dto);
                $result = $this->commandBus->execute($command);
                if ($addItem) {

                    return $this->redirectToRoute('app_cabinet_proposals_general_proposal_update', ['id' => $result->id, 'add_item' => $addItem]);
                }
                $this->addFlash('general_proposal_info_created_success', sprintf('Форма "%s" добавлена.', $dto->number));

                return $this->redirectToRoute('app_cabinet_proposals_general_proposal_list');
            }

            return $this->render('cabinet/proposal/create.html.twig', compact(array_keys(get_defined_vars())));
        } catch (\Throwable $e) {
            $error = $this->getClientErrorMessage($e);

            return $this->render('cabinet/proposal/create.html.twig', compact(array_keys(get_defined_vars())));
        }
    }
}