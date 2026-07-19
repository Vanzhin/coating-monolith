<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingTag;

use App\Coatings\Application\UseCase\Query\SuggestTags\SuggestTagsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/coating-tag/suggest',
    name: 'app_cabinet_coating_coating_tag_suggest',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class SuggestTagsAction extends AbstractController
{
    private const MAX_LIMIT = 25;
    private const DEFAULT_LIMIT = 10;

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $type = $request->query->get('type');
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ('' === $q) {
            return new JsonResponse([]);
        }

        /** @var \App\Coatings\Application\UseCase\Query\SuggestTags\SuggestTagsQueryResult $result */
        $result = $this->queryBus->execute(new SuggestTagsQuery($q, $type ?: null, $limit));

        $payload = array_map(
            static fn ($dto) => ['id' => $dto->id, 'title' => $dto->title],
            $result->tags,
        );

        return new JsonResponse($payload);
    }
}
