<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Coatings\Application\UseCase\Command\CreateCoating\CreateCoatingCommand;
use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoUnit;
use App\Proposals\Infrastructure\Adapter\CoatingsAdapter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals', name: 'app_cabinet_proposals_general_proposal')]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface   $queryBus,
        private readonly Validator           $validator,
        private readonly CoatingMapper       $coatingMapper,
        private readonly CoatingsAdapter     $coatingsAdapter,

    )
    {
    }

    public function __invoke(Request $request): Response
    {
        $coatings = $this->coatingsAdapter->getPagedCoatings();

        $data = [
            'units' => GeneralProposalInfoUnit::values(),
            'durabilities' => CoatingSystemDurability::values(),
            'categories' => CoatingSystemCorrosiveCategory::values(),
            'treatments' => CoatingSystemSurfaceTreatment::values(),
            'methods' => CoatingSystemApplicationMethod::values(),
        ];
        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                $inputData = $request->getPayload()->all();
                dd($inputData);
                $errors = $this->validator->validate($request->getPayload()->all(), $this->coatingMapper->getValidationCollectionCoating());
                if ($errors) {
                    throw new \Exception(current($errors)->getFullMessage());
                }
                $dto = $this->coatingMapper->buildCoatingDtoFromInputData($inputData);
                $command = new CreateCoatingCommand($dto);
                $this->commandBus->execute($command);
                $this->addFlash('manufacturer_created_success', sprintf('Покрытие "%s" добавлено.', $dto->title));

                return $this->redirectToRoute('app_cabinet_coating_coating_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/coating/coating/create.html.twig', compact('error', 'inputData'));
            }
        }

        return $this->render('cabinet/proposal/create.html.twig', compact('coatings', 'data'));
    }
}