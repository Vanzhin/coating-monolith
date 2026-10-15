<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Списать выбранные» с карточки выдачи: кладёт held-факты отмеченных позиций в черновик акта списания
 * (корзину) и возвращает на карточку — там акт показан в списке, оформление на его странице.
 */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/write-off/add',
    name: 'app_cabinet_compliance_writeoff_add',
    methods: ['POST'],
)]
final class AddToWriteOffAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId): Response
    {
        /** @var list<string> $keys */
        $keys = array_values(array_filter(
            array_map(static fn (mixed $k): string => (string) $k, $request->getPayload()->all('keys')),
            static fn (string $k): bool => '' !== $k,
        ));
        try {
            $this->commandBus->execute(new AddToWriteOffDraftCommand($profileId, $requirementId, $keys));
            $this->addFlash('success', 'Выбранные позиции добавлены в акт списания.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
    }
}
