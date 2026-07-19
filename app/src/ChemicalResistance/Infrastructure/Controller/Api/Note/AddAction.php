<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Api\Note;

use App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote\CreateNoteCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/chemical-resistance/note', name: 'app_api_chemical_resistance_note_add', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
class AddAction
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getPayload()->all();
        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));

        try {
            /** @var string $id */
            $id = $this->commandBus->execute(new CreateNoteCommand(
                title: $title,
                description: $description,
            ));
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            [
                'id' => $id,
                'title' => $title,
                'description' => $description,
            ],
            Response::HTTP_CREATED,
        );
    }
}
