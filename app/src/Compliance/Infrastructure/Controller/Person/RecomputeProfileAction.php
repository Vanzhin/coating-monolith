<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Person;

use App\Compliance\Application\UseCase\Command\RecomputeProfile\RecomputeProfileCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Админ принудительно пересобирает проекцию человека (ручной fallback, если событийный пересчёт пропал/упал). */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/recompute',
    name: 'app_cabinet_compliance_recompute',
    methods: ['POST'],
)]
final class RecomputeProfileAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $profileId): Response
    {
        try {
            $this->commandBus->execute(new RecomputeProfileCommand($profileId));
            $this->addFlash('success', 'Учёт сотрудника пересчитан.');
        } catch (AppException $e) {
            // Прав-/доменные ошибки — человекочитаемый flash; технические не глушим.
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_acts', ['profile' => $profileId]);
    }
}
