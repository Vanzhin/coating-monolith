<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingTag;

use App\Coatings\Application\UseCase\Command\CreateGeneralTag\CreateGeneralTagCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/coating-tag',
    name: 'app_cabinet_coating_coating_tag_create',
    methods: ['POST'],
)]
#[IsGranted('ROLE_ADMIN')]
final class CreateGeneralTagAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $title = is_array($payload) ? (string) ($payload['title'] ?? '') : '';

        try {
            /** @var \App\Coatings\Application\UseCase\Command\CreateGeneralTag\CreateGeneralTagCommandResult $result */
            $result = $this->commandBus->execute(new CreateGeneralTagCommand($title));
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['id' => $result->id, 'title' => $result->title],
            Response::HTTP_CREATED,
        );
    }
}
