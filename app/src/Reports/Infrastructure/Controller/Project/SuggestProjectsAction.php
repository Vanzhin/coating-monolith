<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Project;

use App\Reports\Application\UseCase\Query\SuggestProjects\SuggestProjectsQuery;
use App\Reports\Application\UseCase\Query\SuggestProjects\SuggestProjectsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/reports/project/suggest',
    name: 'app_cabinet_reports_project_suggest',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class SuggestProjectsAction extends AbstractController
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

        if ('' === $q) {
            return new JsonResponse(['items' => []]);
        }

        $result = $this->queryBus->execute(new SuggestProjectsQuery($q, $limit));
        \assert($result instanceof SuggestProjectsQueryResult);

        $items = array_map(
            static fn ($dto) => ['id' => $dto->id, 'title' => $dto->title, 'counterparty' => $dto->counterpartyTitle],
            $result->projects,
        );

        return new JsonResponse(['items' => $items]);
    }
}
