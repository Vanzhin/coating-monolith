<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Issuer;

use App\Certificates\Application\UseCase\Query\SuggestIssuers\SuggestIssuersQuery;
use App\Certificates\Application\UseCase\Query\SuggestIssuers\SuggestIssuersQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/certificate/issuer/suggest',
    name: 'app_cabinet_certificate_issuer_suggest',
    methods: ['GET'],
)]
// Исключение из правила «авторизация в хендлере»: typeahead организаций только для админ-формы
// документа; SuggestIssuersQuery не гейтим (read), ограничиваем эндпоинт на контроллере.
#[IsGranted('ROLE_ADMIN')]
final class SuggestIssuersAction extends AbstractController
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
            return new JsonResponse([]);
        }

        $result = $this->queryBus->execute(new SuggestIssuersQuery($q, $limit));
        \assert($result instanceof SuggestIssuersQueryResult);

        $payload = array_map(
            static fn ($dto) => ['id' => $dto->id, 'title' => $dto->title],
            $result->issuers,
        );

        return new JsonResponse($payload);
    }
}
