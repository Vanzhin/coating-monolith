<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Списать» с акта выдачи: открыть кросс-персон черновик акта списания по требованию (создать, наполнив всеми
 * held-фактами человека, если открытого ещё нет) и провалиться в него. Существующую корзину (напр. из «Списать
 * выбранные») не переформировываем — просто открываем её.
 */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/write-off/open',
    name: 'app_cabinet_compliance_writeoff_open',
    methods: ['POST'],
)]
final class GoToWriteOffAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly WriteOffActRepositoryInterface $writeOffActRepository,
    ) {
    }

    public function __invoke(string $profileId, string $requirementId): Response
    {
        $backToIssue = $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);

        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
        $organizationId = $profileResult->profile?->organizationId;
        if (null === $organizationId) {
            $this->addFlash('danger', 'Сотрудник не найден.');

            return $backToIssue;
        }

        $act = $this->writeOffActRepository->findOpenDraft($requirementId, $organizationId);
        if (null === $act) {
            // Открытого черновика нет — наполняем корзину всеми held-фактами человека («Списать всё»).
            try {
                $this->commandBus->execute(new AddToWriteOffDraftCommand($profileId, $requirementId, null));
            } catch (AppException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $backToIssue;
            }
            $act = $this->writeOffActRepository->findOpenDraft($requirementId, $organizationId);
        }

        if (null === $act) {
            $this->addFlash('warning', 'Списать можно только то, что выдано и на руках.');

            return $backToIssue;
        }

        return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['actId' => $act->getId()]);
    }
}
