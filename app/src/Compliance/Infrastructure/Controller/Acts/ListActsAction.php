<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Acts;

use App\Compliance\Application\UseCase\Query\Acts\GetComplianceActsQuery;
use App\Compliance\Application\UseCase\Query\Acts\GetComplianceActsQueryResult;
use App\Compliance\Domain\Aggregate\ProfileCompliance\DocumentStatus;
use App\Compliance\Domain\Repository\ComplianceActsSort;
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
 * Список актов выдачи по сотрудникам. Owner-скоуп — в хендлере (админ видит всех, остальные — только свои).
 * Состояние фильтра — в URL. Контроллер тонкий: собрать запрос → рендер. `?partial=1` отдаёт голый батч строк
 * для догрузки (infinite-list).
 */
#[Route(path: '/cabinet/compliance/acts', name: 'app_cabinet_compliance_acts', methods: ['GET'])]
final class ListActsAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = $request->query;
        $search = trim((string) $q->get('q', '')) ?: null;
        $requirementId = trim((string) $q->get('requirement', '')) ?: null;
        $status = DocumentStatus::tryFrom((string) $q->get('status', ''));
        $sort = ComplianceActsSort::tryFrom((string) $q->get('sort', '')) ?? ComplianceActsSort::DEFAULT;
        $dateFrom = $this->date((string) $q->get('from', ''));
        $dateTo = $this->date((string) $q->get('to', ''));
        /** @var list<string> $positionIds */
        $positionIds = array_values(array_filter((array) $q->all('pos')));
        $profileIds = $this->readProfileIds($request);
        $page = max(1, (int) $q->get('page', 1));

        /** @var GetComplianceActsQueryResult $result */
        $result = $this->queryBus->execute(new GetComplianceActsQuery(
            q: $search,
            profileIds: new StringCollection(...$profileIds),
            positionIds: new StringCollection(...$positionIds),
            requirementId: $requirementId,
            status: $status,
            dateFrom: $dateFrom,
            dateTo: $this->endOfDay($dateTo),
            sort: $sort,
            pager: Pager::fromPage($page, 10),
        ));

        // Догрузка (infinite-list): только батч строк, без шапки/фильтра. Пусто → пустой ответ (контроллер стопает).
        if ('1' === $q->get('partial')) {
            return $this->render('admin/compliance/acts/_batch.html.twig', ['acts' => $result->acts]);
        }

        return $this->render('admin/compliance/acts/index.html.twig', [
            'result' => $result,
            'q' => $search,
            'requirementId' => $requirementId,
            'status' => $status?->value,
            'sort' => $sort->value,
            'from' => $q->get('from'),
            'to' => $q->get('to'),
            'profileIds' => $profileIds,
            'profileNames' => $this->resolveNames($profileIds),
        ]);
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        return '' === $value ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null);
    }

    /** Конец дня для верхней границы периода (иначе «по 10.10» отсекало бы акты этого же дня). */
    private function endOfDay(?\DateTimeImmutable $date): ?\DateTimeImmutable
    {
        return $date?->setTime(23, 59, 59);
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
            $names[$profile->id] = trim(sprintf('%s %s %s', $profile->lastName, $profile->firstName, $profile->middleName ?? ''));
        }

        return $names;
    }
}
