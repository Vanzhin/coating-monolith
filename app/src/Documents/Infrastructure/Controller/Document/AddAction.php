<?php

declare(strict_types=1);


namespace App\Documents\Infrastructure\Controller\Document;

use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route(path: '/cabinet/document', name: 'app_cabinet_document_create')]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly Validator $validator,
        private readonly CoatingMapper $coatingMapper,

    ) {
    }

    public function __invoke(Request $request): Response
    {
        //todo
        try {
            if ($request->isMethod(Request::METHOD_POST)) {
                $inputData = $request->getPayload()->all();
                dd($inputData);
//                $inputData['ownerId'] = $this->getUser()->getUlid();
//                $errors = $this->validator->validate($inputData,
//                    $this->generalProposalInfoMapper->getValidationCollectionGeneralProposalInfo());
//                if ($errors) {
//                    throw new \Exception(current($errors)->getFullMessage());
//                }
//                $dto = $this->generalProposalInfoMapper->buildDtoFromInputData($inputData);
//                $command = new CreateGeneralProposalInfoCommand($dto);
//                $result = $this->commandBus->execute($command);
//                if ($addItem) {
//                    return $this->redirectToRoute('app_cabinet_proposals_general_proposal_update',
//                        ['id' => $result->id, 'add_item' => $addItem]);
//                }
//                $this->addFlash('general_proposal_info_created_success',
//                    sprintf('Форма "%s" добавлена.', $dto->number));
//
//                return $this->redirectToRoute('app_cabinet_proposals_general_proposal_list');
            }

            return $this->render('cabinet/proposal/create.html.twig', compact(array_keys(get_defined_vars())));
        } catch (\Throwable $e) {
//            $error = $this->getClientErrorMessage($e);

            return $this->render('cabinet/proposal/create.html.twig', compact(array_keys(get_defined_vars())));
        }
    }

}