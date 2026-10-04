<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\UseCase\Command\DeleteWriteOffAct\DeleteWriteOffActCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Удалить черновик акта списания и вернуться к акту выдачи (его требованию). */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/writeoff/{actId}/delete',
    name: 'app_cabinet_compliance_writeoff_delete',
    methods: ['POST'],
)]
final class DeleteAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(string $profileId, string $actId): Response
    {
        // Требование акта нужно до удаления — чтобы вернуться на его акт выдачи.
        $requirementId = null;
        foreach ($this->repository->findByProfile($profileId)?->getWriteOffActs() ?? [] as $act) {
            if ($act->getId() === $actId) {
                $requirementId = $act->requirementId();
                break;
            }
        }

        try {
            $this->commandBus->execute(new DeleteWriteOffActCommand($profileId, $actId));
            $this->addFlash('success', 'Акт списания удалён.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        if (null !== $requirementId) {
            return $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
        }

        return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
    }
}
