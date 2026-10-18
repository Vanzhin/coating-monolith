<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\UseCase\Query\GetPagedWriteOffActs\GetPagedWriteOffActsQuery;
use App\Compliance\Application\UseCase\Query\ListRequirements\ListRequirementsQuery;
use App\Compliance\Application\UseCase\Query\ListRequirements\ListRequirementsQueryResult;
use App\Compliance\Domain\Repository\WriteOffActFilter;
use App\Compliance\Domain\Type\DocumentStatus;
use App\Reports\Application\UseCase\Query\GetCounterparty\GetCounterpartyQuery;
use App\Reports\Application\UseCase\Query\GetCounterparty\GetCounterpartyQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Список актов списания — кросс-организационный, только админ (гейт в хендлере). Фильтр (организация/
 * требование/статус/№ акта) живёт в URL query-параметрах. Опции фильтра (каталог требований, название
 * выбранной организации для гидрации поля) — отдельные лёгкие query для рендера, без бизнес-логики тут.
 */
#[Route(path: '/cabinet/compliance/writeoff', name: 'app_cabinet_compliance_writeoff_list', methods: ['GET'])]
final class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $organizationId = trim((string) $request->query->get('organizationId', '')) ?: null;
        $requirementId = trim((string) $request->query->get('requirementId', '')) ?: null;
        $status = DocumentStatus::tryFrom((string) $request->query->get('status', ''));
        $actNumber = trim((string) $request->query->get('actNumber', '')) ?: null;

        $filter = new WriteOffActFilter(
            pager: Pager::fromPage($page, 20),
            organizationId: $organizationId,
            requirementId: $requirementId,
            status: $status,
            actNumber: $actNumber,
        );
        $result = $this->queryBus->execute(new GetPagedWriteOffActsQuery($filter));

        /** @var ListRequirementsQueryResult $requirementsResult */
        $requirementsResult = $this->queryBus->execute(new ListRequirementsQuery(Pager::fromPage(1, 500)));

        $organizationTitle = null;
        if (null !== $organizationId) {
            /** @var GetCounterpartyQueryResult $counterpartyResult */
            $counterpartyResult = $this->queryBus->execute(new GetCounterpartyQuery($organizationId));
            $organizationTitle = $counterpartyResult->counterparty?->title;
        }

        return $this->render('admin/compliance/writeoff/index.html.twig', [
            'result' => $result,
            'requirements' => $requirementsResult->requirements,
            'statuses' => DocumentStatus::cases(),
            'organizationId' => $organizationId,
            'organizationTitle' => $organizationTitle,
            'requirementId' => $requirementId,
            'status' => $status?->value,
            'actNumber' => $actNumber,
        ]);
    }
}
