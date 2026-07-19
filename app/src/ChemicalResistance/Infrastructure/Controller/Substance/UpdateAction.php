<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Command\Substance\UpdateSubstance\UpdateSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Query\GetSubstance\GetSubstanceQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/chemical-resistance/substance/{id}/edit',
    name: 'app_cabinet_chemical_resistance_substance_update',
    requirements: ['id' => '[0-9a-f-]{36}'],
)]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $result = $this->queryBus->execute(new GetSubstanceQuery($id));
        if (null === $result->substance) {
            $this->addFlash('substance_edited_error', sprintf('Вещество с идентификатором «%s» не найдено.', $id));

            return $this->redirectToRoute('app_cabinet_chemical_resistance_substance_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $this->commandBus->execute(new UpdateSubstanceCommand(
                    id: $id,
                    canonicalName: (string) ($inputData['canonicalName'] ?? ''),
                    cas: ($inputData['cas'] ?? '') !== '' ? (string) $inputData['cas'] : null,
                    aliases: AliasesParser::parse((string) ($inputData['aliasesText'] ?? '')),
                ));
                $this->addFlash(
                    'substance_updated_success',
                    sprintf('Вещество «%s» было обновлено.', $inputData['canonicalName'] ?? ''),
                );

                return $this->redirectToRoute('app_cabinet_chemical_resistance_substance_list');
            } catch (AppException $e) {
                $error = $e->getMessage();

                return $this->render(
                    'admin/chemical_resistance/substance/form.html.twig',
                    compact('error', 'inputData'),
                );
            }
        }

        $dto = $result->substance;
        $inputData = [
            'id' => $id,
            'canonicalName' => $dto->canonicalName,
            'cas' => $dto->cas ?? '',
            'aliasesText' => implode("\n", $dto->aliases),
        ];

        return $this->render('admin/chemical_resistance/substance/form.html.twig', compact('inputData'));
    }
}
