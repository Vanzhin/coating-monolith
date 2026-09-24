<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Audit\AuditLogView;
use App\Shared\Application\Audit\Query\GetClassAuditLog\GetClassAuditLogQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Журнал изменений покрытий (все объекты класса) с фильтром по актору и покрытию.
 * Только для админов: гейт в GetClassAuditLogQueryHandler (AuditAccessControl →
 * ForbiddenException, 403); UI-ссылка на журнал — под canEdit.
 *
 * Чипы выбранных фасетов (актор/покрытие) НЕ резолвятся на сервере — в шаблон уходят
 * только id из URL, названия дотягивает клиентская by-ids гидрация (конвенция фильтров
 * кабинета, см. coating_system/list.html.twig: preselected-ids + resolve-url). А вот
 * titlesByEntityId — это заголовки объектов в самой ЛЕНТЕ (контент, не фильтр), они
 * резолвятся тут, чтобы ссылки вели по названию, а не по голому UUID.
 */
#[Route(path: '/cabinet/coating/coating/audit-journal', name: 'app_cabinet_coating_coating_audit_journal', methods: ['GET'])]
class AuditJournalAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingRepositoryInterface $coatingRepository,
        private readonly QueryParams $queryParams,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $actorIds = $this->queryParams->stringCollection($request, 'actorIds');
        $coatingIds = $this->queryParams->stringCollection($request, 'coatingIds');
        $page = max(1, (int) $request->query->get('page', 1));

        $log = $this->queryBus->execute(new GetClassAuditLogQuery(Coating::class, $actorIds, $coatingIds, $page));

        return $this->render('admin/coating/coating/audit_journal.html.twig', [
            'log' => $log,
            'actorIds' => $actorIds->getList(),
            'coatingIds' => $coatingIds->getList(),
            'entityTitles' => $this->titlesByEntityId($log),
        ]);
    }

    /**
     * Заголовки покрытий для ссылок в журнале — вместо голого UUID. Удалённое
     * покрытие просто не попадёт в карту, шаблон откатится на id (|default).
     *
     * @param list<AuditLogView> $log
     *
     * @return array<string, string>
     */
    private function titlesByEntityId(array $log): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (AuditLogView $entry): string => $entry->entityId,
            $log,
        )));

        if ([] === $ids) {
            return [];
        }

        $titles = [];
        foreach ($this->coatingRepository->findByIds(new StringCollection(...$ids)) as $coating) {
            $titles[$coating->getId()] = $coating->getTitle();
        }

        return $titles;
    }
}
