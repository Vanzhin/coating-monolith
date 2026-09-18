<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Project;

use App\Reports\Application\UseCase\Command\UpdateProject\UpdateProjectCommand;
use App\Reports\Application\UseCase\Query\GetProject\GetProjectQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/reports/project/{id}/edit',
    name: 'app_cabinet_reports_project_update',
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
        $result = $this->queryBus->execute(new GetProjectQuery($id));
        if (null === $result->project) {
            $this->addFlash('project_updated_error', sprintf('Проект «%s» не найден.', $id));

            return $this->redirectToRoute('app_cabinet_reports_project_list');
        }

        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $this->commandBus->execute(new UpdateProjectCommand(
                    $id,
                    (string) ($inputData['title'] ?? ''),
                    (string) ($inputData['counterpartyId'] ?? ''),
                    $this->nullableString($inputData['description'] ?? null),
                ));
                $this->addFlash('project_updated_success', sprintf('Проект «%s» обновлён.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_reports_project_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
            }
        } else {
            $inputData = [
                'id' => $id,
                'title' => $result->project->title,
                'description' => $result->project->description,
                'counterpartyId' => $result->project->counterpartyId,
                'counterpartyTitle' => $result->project->counterpartyTitle,
            ];
        }

        return $this->render('admin/reports/project/form.html.twig', compact('error', 'inputData'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
