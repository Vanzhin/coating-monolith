<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Dashboard;

use App\Compliance\Application\UseCase\Query\Dashboard\ComplianceDashboardFilter;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetComplianceOverviewQueryResult;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQueryResult;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceType;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Дашборд «Соответствие»: картина (KPI) + разрез (люди/отделы/требования) фильтром. Owner-скоуп — в хендлере
 * (админ видит всех, остальные — себя). Состояние фильтра — в URL. Контроллер тонкий: query → фильтр → рендер.
 */
#[Route(path: '/cabinet/compliance/dashboard', name: 'app_cabinet_compliance_dashboard', methods: ['GET'])]
final class IndexAction extends AbstractController
{
    private const LENSES = ['people', 'dept', 'req'];

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = $request->query;
        $lens = \in_array($q->get('lens'), self::LENSES, true) ? $q->get('lens') : 'people';
        $search = trim((string) $q->get('q', '')) ?: null;
        $type = ComplianceType::tryFrom((string) $q->get('type', ''));
        $onlyProblems = '1' === $q->get('only');
        $statusBucket = ComplianceBucket::tryFrom((string) $q->get('status', ''));
        /** @var list<string> $departmentIds */
        $departmentIds = array_values(array_filter((array) $q->all('dept')));
        /** @var list<string> $positionIds */
        $positionIds = array_values(array_filter((array) $q->all('pos')));
        $profileIds = $this->readProfileIds($request);
        $page = max(1, (int) $q->get('page', 1));

        $filter = new ComplianceDashboardFilter(
            q: $search,
            departmentIds: new StringCollection(...$departmentIds),
            positionIds: new StringCollection(...$positionIds),
            type: $type,
            onlyProblems: $onlyProblems,
            statusBucket: $statusBucket,
            profileIds: new StringCollection(...$profileIds),
            pager: Pager::fromPage($page, 10),
        );

        /** @var GetComplianceOverviewQueryResult $overview */
        $overview = $this->queryBus->execute(new GetComplianceOverviewQuery($filter));

        $people = null;
        if ('people' === $lens) {
            /** @var GetPagedComplianceQueryResult $people */
            $people = $this->queryBus->execute(new GetPagedComplianceQuery($filter));
        }

        return $this->render('admin/compliance/dashboard/index.html.twig', [
            'lens' => $lens,
            'overview' => $overview,
            'people' => $people,
            'q' => $search,
            'type' => $type?->value,
            'onlyProblems' => $onlyProblems,
            'status' => $statusBucket?->value,
            'profileIds' => $profileIds,
            'profileNames' => $this->resolveNames($profileIds),
        ]);
    }

    /**
     * Читает `profile` из query — принимает и `?profile=id`, и `?profile[]=id1&profile[]=id2`.
     *
     * @return list<string>
     */
    private function readProfileIds(Request $request): array
    {
        $raw = $request->query->all()['profile'] ?? null;
        if (null === $raw) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            \is_array($raw) ? $raw : [$raw],
        )));
    }

    /**
     * @param list<string> $profileIds
     *
     * @return array<string, string> id → ФИО (для чипов)
     */
    private function resolveNames(array $profileIds): array
    {
        if ([] === $profileIds) {
            return [];
        }
        /** @var GetProfilesByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfilesByIdsQuery(new StringCollection(...$profileIds)));

        $names = [];
        foreach ($result->profiles as $profile) {
            $names[$profile->id] = $this->fullName($profile);
        }

        return $names;
    }

    private function fullName(ProfileDTO $profile): string
    {
        return trim($profile->lastName.' '.$profile->firstName.' '.($profile->middleName ?? ''));
    }
}
