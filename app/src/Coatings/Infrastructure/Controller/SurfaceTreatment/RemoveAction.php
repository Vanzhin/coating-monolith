<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Application\UseCase\Command\RemoveSurfaceTreatment\RemoveSurfaceTreatmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/cabinet/coating/surface-treatment/{id}/remove', name: 'app_cabinet_surface_treatment_remove', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
class RemoveAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        try {
            $this->commandBus->execute(new RemoveSurfaceTreatmentCommand($id));
            $this->addFlash('surface_treatment_removed_success', 'Подготовка поверхности удалена.');
        } catch (\Exception $e) {
            $this->addFlash('surface_treatment_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_surface_treatment_list');
    }
}
