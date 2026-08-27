<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/chemical-resistance/substance', name: 'app_cabinet_chemical_resistance_substance_create')]
class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $inputData = [];

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $this->commandBus->execute(new CreateSubstanceCommand(
                    canonicalName: (string) ($inputData['canonicalName'] ?? ''),
                    cas: ($inputData['cas'] ?? '') !== '' ? (string) $inputData['cas'] : null,
                    aliases: AliasesParser::parse((string) ($inputData['aliasesText'] ?? '')),
                ));
                $this->addFlash(
                    'substance_created_success',
                    sprintf('Вещество «%s» было добавлено.', $inputData['canonicalName'] ?? ''),
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

        return $this->render('admin/chemical_resistance/substance/form.html.twig', compact('inputData'));
    }
}
