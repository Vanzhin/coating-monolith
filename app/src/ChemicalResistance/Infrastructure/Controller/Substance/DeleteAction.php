<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance\DeleteSubstanceCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/chemical-resistance/substance/{id}/delete',
    name: 'app_cabinet_chemical_resistance_substance_delete',
    requirements: ['id' => '[0-9a-f-]{36}'],
)]
class DeleteAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id): Response
    {
        try {
            $this->commandBus->execute(new DeleteSubstanceCommand($id));
            $this->addFlash('substance_removed_success', 'Вещество удалено.');
        } catch (AppException $e) {
            $this->addFlash('substance_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_chemical_resistance_substance_list');
    }
}
