<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\StartWriteOffAct\StartWriteOffActCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** «Перейти к акту списания» с акта выдачи: открыть черновик акта списания (создать, если открытого нет) и провалиться в него. */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/write-off/open',
    name: 'app_cabinet_compliance_writeoff_open',
    methods: ['POST'],
)]
final class GoToWriteOffAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(string $profileId, string $requirementId): Response
    {
        try {
            $this->commandBus->execute(new StartWriteOffActCommand($profileId, $requirementId));
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
        }

        $act = $this->repository->findByProfile($profileId)?->openWriteOffDraftFor($requirementId);
        if (null === $act) {
            $this->addFlash('warning', 'Не удалось открыть акт списания.');

            return $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
        }

        return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['profileId' => $profileId, 'actId' => $act->getId()]);
    }
}
