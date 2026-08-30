<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\Coatings\Application\UseCase\Query\GetCoatingsBySubstance\GetCoatingsBySubstanceQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingsBySubstance\GetCoatingsBySubstanceQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Страница «Химстойкость»: поиск по веществам (мультивыбор, логика AND) → покрытия,
 * стойкие ко ВСЕМ выбранным (тонкий контроллер). Просмотр — все авторизованные;
 * управление (добавить/править оценку, завести вещество) — только админ, гейт в командах.
 */
#[Route(
    path: '/cabinet/coating/coating/by-substance',
    name: 'app_cabinet_coating_coating_by_substance',
    methods: ['GET'],
)]
class BySubstanceAction extends AbstractController
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly QueryParams $queryParams,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $substanceIds = $this->queryParams->stringCollection($request, 'substanceIds', unique: true);
        $includeAll = $request->query->getBoolean('includeAll');
        $page = max(1, $request->query->getInt('page', 1));
        $canEdit = $this->isGranted('ROLE_ADMIN');

        $result = null;
        if ($substanceIds->count() > 0) {
            /** @var GetCoatingsBySubstanceQueryResult $result */
            $result = $this->queryBus->execute(
                new GetCoatingsBySubstanceQuery($substanceIds, $includeAll, $page, self::PER_PAGE),
            );
        }

        // Infinite-scroll: догрузка отдаёт голый partial с карточками.
        if (null !== $result && $request->query->getBoolean('partial')) {
            return $this->render('cabinet/chemical_resistance/_resistant_cards.html.twig', [
                'items' => $result->items,
                'canEdit' => $canEdit,
                'grades' => Grade::cases(),
            ]);
        }

        return $this->render('cabinet/chemical_resistance/by_substance.html.twig', [
            'includeAll' => $includeAll,
            'canEdit' => $canEdit,
            'result' => $result,
            'perPage' => self::PER_PAGE,
            'grades' => Grade::cases(),
        ]);
    }
}
