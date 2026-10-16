<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\UseCase\Command\DeleteWriteOffAct\DeleteWriteOffActCommand;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Удалить черновик акта списания и вернуться к акту выдачи (его требование + первый затронутый человек). */
#[Route(
    path: '/cabinet/compliance/writeoff/{actId}/delete',
    name: 'app_cabinet_compliance_writeoff_delete',
    methods: ['POST'],
)]
final class DeleteAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly WriteOffActRepositoryInterface $writeOffActRepository,
    ) {
    }

    public function __invoke(string $actId): Response
    {
        // Требование и человек акта нужны до удаления — чтобы вернуться на его акт выдачи.
        $act = $this->writeOffActRepository->findById($actId);
        $requirementId = $act?->requirementId();
        $profileId = null !== $act ? ($act->profileIds()[0] ?? null) : null;

        try {
            $this->commandBus->execute(new DeleteWriteOffActCommand($actId));
            $this->addFlash('success', 'Акт списания удалён.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        if (null !== $requirementId && null !== $profileId) {
            return $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
        }

        return $this->redirectToRoute('app_cabinet_compliance_dashboard');
    }
}
