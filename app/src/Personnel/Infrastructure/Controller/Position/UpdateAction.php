<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Position;

use App\Personnel\Application\UseCase\Command\UpdatePosition\UpdatePositionCommand;
use App\Personnel\Application\UseCase\Query\GetPosition\GetPositionQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/cabinet/personnel/position/{id}/edit',
    name: 'app_cabinet_personnel_position_update',
    methods: ['GET', 'POST'],
)]
final class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $result = $this->queryBus->execute(new GetPositionQuery($id));
        if (null === $result->position) {
            $this->addFlash('position_updated_error', sprintf('Должность «%s» не найдена.', $id));

            return $this->redirectToRoute('app_cabinet_personnel_position_list');
        }

        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $this->commandBus->execute(new UpdatePositionCommand($id, (string) ($inputData['title'] ?? '')));
                $this->addFlash('position_updated_success', sprintf('Должность «%s» обновлена.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_personnel_position_list');
            } catch (AppException $e) {
                $error = $e->getMessage();
            }
        } else {
            $inputData = ['id' => $id, 'title' => $result->position->title];
        }

        return $this->render('admin/personnel/position/form.html.twig', compact('error', 'inputData'));
    }
}
