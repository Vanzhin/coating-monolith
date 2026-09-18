<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Counterparty;

use App\Reports\Application\UseCase\Command\UpdateCounterparty\UpdateCounterpartyCommand;
use App\Reports\Application\UseCase\Query\GetCounterparty\GetCounterpartyQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/reports/counterparty/{id}/edit',
    name: 'app_cabinet_reports_counterparty_update',
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
        $result = $this->queryBus->execute(new GetCounterpartyQuery($id));
        if (null === $result->counterparty) {
            $this->addFlash('counterparty_updated_error', sprintf('Контрагент «%s» не найден.', $id));

            return $this->redirectToRoute('app_cabinet_reports_counterparty_list');
        }

        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $this->commandBus->execute(new UpdateCounterpartyCommand(
                    $id,
                    (string) ($inputData['title'] ?? ''),
                    $this->nullableString($inputData['description'] ?? null),
                ));
                $this->addFlash('counterparty_updated_success', sprintf('Контрагент «%s» обновлён.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_reports_counterparty_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
            }
        } else {
            $inputData = [
                'id' => $id,
                'title' => $result->counterparty->title,
                'description' => $result->counterparty->description,
            ];
        }

        return $this->render('admin/reports/counterparty/form.html.twig', compact('error', 'inputData'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
