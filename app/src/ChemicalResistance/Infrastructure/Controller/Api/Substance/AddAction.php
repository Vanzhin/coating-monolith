<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Api\Substance;

use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommand;
use App\ChemicalResistance\Infrastructure\Controller\Substance\AliasesParser;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/chemical-resistance/substance', name: 'app_api_chemical_resistance_substance_add', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
class AddAction
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
