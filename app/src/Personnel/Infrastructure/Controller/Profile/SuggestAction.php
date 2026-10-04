<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Profile;

use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\UseCase\Query\SuggestProfiles\SuggestProfilesQuery;
use App\Personnel\Application\UseCase\Query\SuggestProfiles\SuggestProfilesQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * typeahead сотрудников по ФИО (фильтр дашборда «Соответствие»). Read-запрос не гейтим — доступ к
 * данным всё равно owner-скоупит хендлер дашборда (не-админ видит только себя).
 */
#[Route(path: '/cabinet/personnel/profile/suggest', name: 'app_cabinet_personnel_profile_suggest', methods: ['GET'])]
final class SuggestAction extends AbstractController
{
    private const MAX_LIMIT = 25;
    private const DEFAULT_LIMIT = 10;

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));
        $organizationId = trim((string) $request->query->get('organizationId', '')) ?: null;

        if ('' === $q) {
            return new JsonResponse(['items' => []]);
        }

        /** @var SuggestProfilesQueryResult $result */
        $result = $this->queryBus->execute(new SuggestProfilesQuery($q, $limit, $organizationId));

        $items = array_map(
            static fn (ProfileDTO $p): array => [
                'id' => $p->id,
                'title' => trim($p->lastName.' '.$p->firstName.' '.($p->middleName ?? '')),
            ],
            $result->profiles,
        );

        return new JsonResponse(['items' => $items]);
    }
}
