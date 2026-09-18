<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest\AllCoatingsForSuggestQuery;
use App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest\AllCoatingsForSuggestQueryResult;
use App\Coatings\Infrastructure\Api\CoatingSuggestNormalizer;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Весь каталог покрытий одним ответом — источник для офлайн-кеша устройства (IndexedDB),
 * из которого пикер отчёта и калькуляторы /tools ищут покрытие без сети.
 *
 * Условный GET сделан НЕ через HTTP-заголовок ETag, а через версию в ТЕЛЕ: глобальный
 * ResponseListener пересобирает любой application/json в конверт {result,status,data,message}
 * и теряет заголовки ответа (включая ETag) — клиент не смог бы его прочитать. Поэтому:
 *  - 200: тело {items, version}, version = sha1 сериализованного items; клиент хранит version;
 *  - повторный запрос шлёт version в If-None-Match (заголовок ЗАПРОСА конверт не трогает);
 *  - совпало → чистый 304 без тела (не json → ResponseListener его не оборачивает).
 * Так «полная замена по версии» работает поверх конверта. Каталог глобален, приватного в нём нет.
 */
#[Route('/cabinet/coating/coating/catalog', name: 'app_cabinet_coating_coating_catalog', methods: ['GET'])]
final class CatalogAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        /** @var AllCoatingsForSuggestQueryResult $result */
        $result = $this->queryBus->execute(new AllCoatingsForSuggestQuery());
        $items = array_map([CoatingSuggestNormalizer::class, 'toArray'], $result->coatings);

        $version = sha1((string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($request->headers->get('If-None-Match') === $version) {
            return new Response('', Response::HTTP_NOT_MODIFIED);
        }

        return new JsonResponse(['items' => $items, 'version' => $version]);
    }
}
