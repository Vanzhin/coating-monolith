<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Color;

use App\Coatings\Application\UseCase\Command\CreateColor\CreateColorCommand;
use App\Coatings\Application\UseCase\Command\CreateColor\CreateColorCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/color',
    name: 'app_cabinet_coating_color_create',
    methods: ['POST'],
)]
final class CreateColorAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        $name = (string) ($payload['name'] ?? '');
        $ral = $this->nullableString($payload['ral'] ?? null);
        $hex = $this->nullableString($payload['hex'] ?? null);

        try {
            /** @var CreateColorCommandResult $result */
            $result = $this->commandBus->execute(new CreateColorCommand($name, $ral, $hex));
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['id' => $result->id, 'name' => $result->name, 'ral' => $result->ral, 'hex' => $result->hex, 'label' => $result->label],
            Response::HTTP_CREATED,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return '' === $value ? null : $value;
    }
}
