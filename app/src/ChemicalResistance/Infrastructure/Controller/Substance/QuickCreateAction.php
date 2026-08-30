<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Инлайн-создание вещества из кабинета (JSON) — для автокомплитов на сессионных
 * страницах. Двойник /api/chemical-resistance/substance, но под сессионным
 * фаерволом (^/api — stateless JWT, куку кабинета не примет → 401). Гейт админа —
 * в CreateSubstanceCommand. Возвращает {id, canonicalName, cas}.
 */
#[Route(
    path: '/cabinet/chemical-resistance/substance/quick-create',
    name: 'app_cabinet_chemical_resistance_substance_quick_create',
    methods: ['POST'],
)]
class QuickCreateAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getPayload()->all();
        $canonicalName = trim((string) ($payload['canonicalName'] ?? ''));
        $cas = trim((string) ($payload['cas'] ?? ''));
        $aliasesText = (string) ($payload['aliasesText'] ?? '');

        try {
            /** @var string $id */
            $id = $this->commandBus->execute(new CreateSubstanceCommand(
                canonicalName: $canonicalName,
                cas: '' !== $cas ? $cas : null,
                aliases: AliasesParser::parse($aliasesText),
            ));
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            [
                'id' => $id,
                'canonicalName' => $canonicalName,
                'cas' => '' !== $cas ? $cas : null,
            ],
            Response::HTTP_CREATED,
        );
    }
}
