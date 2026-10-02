<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Query\CalculateNextDue\CalculateNextDueQuery;
use App\Compliance\Application\UseCase\Query\CalculateNextDue\CalculateNextDueQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Подсказка «следующая выдача» для формы: дата + периодичность → дата. Расчёт в домене. Read-запрос. */
#[Route(path: '/cabinet/compliance/next-due', name: 'app_cabinet_compliance_next_due', methods: ['GET'])]
final class NextDueAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = $request->query;
        $number = $q->get('number');

        /** @var CalculateNextDueQueryResult $result */
        $result = $this->queryBus->execute(new CalculateNextDueQuery(
            trim((string) $q->get('date', '')),
            trim((string) $q->get('kind', '')),
            null !== $number && '' !== $number ? (int) $number : null,
            trim((string) $q->get('unit', '')) ?: null,
        ));

        return new JsonResponse(['nextDue' => $result->nextDue]);
    }
}
