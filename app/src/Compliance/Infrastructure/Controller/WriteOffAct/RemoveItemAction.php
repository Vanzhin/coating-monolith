<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\UseCase\Command\CancelWriteOffItem\CancelWriteOffItemCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Откат: вынуть позицию из корзины акта списания (пока черновик); опустевший акт удаляется. */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/writeoff/{actId}/remove-item',
    name: 'app_cabinet_compliance_writeoff_remove_item',
    methods: ['POST'],
)]
final class RemoveItemAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(Request $request, string $profileId, string $actId): Response
    {
        $recordId = (string) $request->getPayload()->get('recordId', '');
        try {
            $this->commandBus->execute(new CancelWriteOffItemCommand($profileId, $actId, $recordId));
            $this->addFlash('success', 'Позиция возвращена в действующие.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        // Акт ещё существует — остаёмся на нём; опустел и удалён — на дашборд.
        $profileCompliance = $this->repository->findByProfile($profileId);
        foreach (null !== $profileCompliance ? $profileCompliance->getWriteOffActs() : [] as $act) {
            if ($act->getId() === $actId) {
                return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['profileId' => $profileId, 'actId' => $actId]);
            }
        }

        return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
    }
}
