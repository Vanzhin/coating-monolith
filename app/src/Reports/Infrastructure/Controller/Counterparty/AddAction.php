<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Counterparty;

use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/cabinet/reports/counterparty/create',
    name: 'app_cabinet_reports_counterparty_create',
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
                $this->commandBus->execute(new CreateCounterpartyCommand(
                    (string) ($inputData['title'] ?? ''),
                    (string) ($inputData['tin'] ?? ''),
                    $this->nullableString($inputData['description'] ?? null),
                ));
                $this->addFlash('counterparty_created_success', sprintf('Контрагент «%s» добавлен.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_reports_counterparty_list');
            } catch (AppException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/reports/counterparty/form.html.twig', compact('error', 'inputData'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
