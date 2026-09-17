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
use App\Users\Application\DTO\UserSuggestDTO;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQuery;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQueryResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Журнал изменений покрытий (все объекты класса) с фильтром по актору и покрытию.
 * Только для админов: гейт в GetClassAuditLogQueryHandler (AuditAccessControl →
 * ForbiddenException, 403); UI-ссылка на журнал — под canEdit.
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
            'selectedActors' => $this->resolveActors($actorIds),
            'selectedCoatings' => $this->resolveCoatings($coatingIds),
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

    /**
     * Email выбранных акторов фасета — для чипов фильтра (существующий
     * гидратор чипов «Актор», тот же гейт canManage, что и у самого журнала).
     *
     * @return list<array{id: string, title: string}>
     */
    private function resolveActors(StringCollection $actorIds): array
    {
        if (0 === $actorIds->count()) {
            return [];
        }

        /** @var GetUsersByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetUsersByIdsQuery($actorIds));

        return array_map(
            static fn (UserSuggestDTO $user): array => ['id' => $user->id, 'title' => $user->title],
            $result->users,
        );
    }

    /**
     * Названия выбранных покрытий фасета — для чипов фильтра. Id-колонка
     * покрытия — Doctrine uuid, мусор из URL уронит запрос (SQLSTATE 22P02) —
     * отсеиваем нераспознаваемые id, как ByIdsAction::__invoke.
     *
     * @return list<array{id: string, title: string}>
     */
    private function resolveCoatings(StringCollection $coatingIds): array
    {
        $validIds = array_values(array_filter(
            $coatingIds->getList(),
            static fn (string $id): bool => Uuid::isValid($id),
        ));

        if ([] === $validIds) {
            return [];
        }

        return array_map(
            static fn (Coating $coating): array => ['id' => $coating->getId(), 'title' => $coating->getTitle()],
            $this->coatingRepository->findByIds(new StringCollection(...$validIds)),
        );
    }
}
