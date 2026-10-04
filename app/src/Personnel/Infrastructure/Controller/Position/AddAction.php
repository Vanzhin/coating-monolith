<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Position;

use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/cabinet/personnel/position/create',
    name: 'app_cabinet_personnel_position_create',
    methods: ['GET', 'POST'],
)]
final class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $inputData = [];
        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $this->commandBus->execute(new CreatePositionCommand((string) ($inputData['title'] ?? '')));
                $this->addFlash('position_created_success', sprintf('Должность «%s» добавлена.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_personnel_position_list');
            } catch (AppException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/personnel/position/form.html.twig', compact('error', 'inputData'));
    }
}
