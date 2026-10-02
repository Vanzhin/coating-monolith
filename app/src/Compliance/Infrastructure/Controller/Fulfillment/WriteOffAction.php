<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\WriteOffPositions\WriteOffPositionsCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Положить порции фактов (recordId+количество) в корзину акта списания требования (черновик, без эффекта). */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/write-off',
    name: 'app_cabinet_compliance_write_off',
    methods: ['POST'],
)]
final class WriteOffAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->getPayload()->all();
        /** @var list<array{recordId?: mixed, quantity?: mixed}> $rawPortions */
        $rawPortions = array_values((array) ($payload['portions'] ?? []));
        $portions = array_map(
            static fn (array $portion): array => [
                'recordId' => (string) ($portion['recordId'] ?? ''),
                'quantity' => (float) ($portion['quantity'] ?? 0),
            ],
            $rawPortions,
        );

        try {
            $this->commandBus->execute(new WriteOffPositionsCommand($profileId, $requirementId, $portions));
            $this->addFlash('success', 'Позиции положены в акт списания (черновик). Оформите акт, чтобы списание вступило в силу.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
    }
}
