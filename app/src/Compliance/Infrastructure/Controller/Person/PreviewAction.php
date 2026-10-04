<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Person;

use App\Compliance\Application\UseCase\Query\Dashboard\ComplianceDashboardFilter;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Фрагмент-модалка карточки человека для дашборда (клик по ecard → entity-preview фетчит и показывает).
 * Данные строятся тем же дашборд-запросом (owner-скоуп: не-админ получит только себя). Зеркаль Coatings PreviewAction.
 */
#[Route(path: '/cabinet/compliance/person/{profileId}/preview', name: 'app_cabinet_compliance_person_preview', methods: ['GET'])]
final class PreviewAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $profileId): Response
    {
        /** @var GetPagedComplianceQueryResult $result */
        $result = $this->queryBus->execute(new GetPagedComplianceQuery(new ComplianceDashboardFilter(profileIds: new StringCollection($profileId))));
        $person = $result->people[0] ?? null;
        if (null === $person) {
            throw $this->createNotFoundException('Учёт по сотруднику не найден.');
        }

        return $this->render('admin/compliance/dashboard/_preview.html.twig', [
            'person' => $person,
        ]);
    }
}
