<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Audit\AuditLogView;
use App\Shared\Application\Audit\Query\GetClassAuditLog\GetClassAuditLogQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Журнал изменений покрытий (все объекты класса) с фильтром по актору. Только для админов:
 * гейт в GetClassAuditLogQueryHandler (AuditAccessControl → ForbiddenException, 403);
 * UI-ссылка на журнал — под canEdit.
 */
#[Route(path: '/cabinet/coating/coating/audit-journal', name: 'app_cabinet_coating_coating_audit_journal', methods: ['GET'])]
class AuditJournalAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingRepositoryInterface $coatingRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $actorId = $request->query->get('actor') ?: null;
        $page = max(1, (int) $request->query->get('page', 1));

        $log = $this->queryBus->execute(new GetClassAuditLogQuery(Coating::class, $actorId, $page));

        return $this->render('admin/coating/coating/audit_journal.html.twig', [
            'log' => $log,
            'actorId' => $actorId,
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
