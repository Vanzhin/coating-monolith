<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\ExcludeObligation\ExcludeObligationCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Убрать позицию» с карточки выдачи: исключает позицию (чаще — личную, вне нормы) из учёта человека и возвращает
 * на карточку. Подтверждение — общей модалкой #deleteModal, поэтому ключ позиции приходит query-параметром
 * (data-bs-url), а CSRF — интеншном 'delete' (как остальные удаления). Факты истории сохраняются (см.
 * {@see \App\Compliance\Application\UseCase\Command\ExcludeObligation\ExcludeObligationCommandHandler}).
 */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/obligation/exclude',
    name: 'app_cabinet_compliance_obligation_exclude',
    methods: ['POST'],
)]
final class ExcludeObligationAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId): Response
    {
        $this->csrfGuard->assertValid('delete', $request->request->getString('_token'));
        $key = trim($request->query->getString('obligationKey'));
        try {
            if ('' !== $key) {
                $this->commandBus->execute(new ExcludeObligationCommand($profileId, $requirementId, $key));
                $this->addFlash('success', 'Позиция убрана из учёта. Выдачи и списания остались в истории.');
            }
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
    }
}
