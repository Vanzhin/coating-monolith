<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\DTO\Colors\ColorDTO;
use App\Coatings\Application\UseCase\Query\GetCoatingColors\GetCoatingColorsQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingColors\GetCoatingColorsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отдаёт цвета конкретного покрытия + флаг колеруемости — для селектора цвета в слое
 * системы: не колеруемое → выбор из возвращённого списка; колеруемое → любой цвет справочника.
 */
#[Route(
    path: '/cabinet/coating/coating/{id}/colors',
    name: 'app_cabinet_coating_coating_colors',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class CoatingColorsAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        /** @var GetCoatingColorsQueryResult $result */
        $result = $this->queryBus->execute(new GetCoatingColorsQuery($id));

        $colors = array_map(
            static fn (ColorDTO $color) => ['id' => $color->id, 'name' => $color->name, 'ral' => $color->ral, 'hex' => $color->hex, 'label' => $color->label],
            $result->colors,
        );

        return new JsonResponse(['isTintable' => $result->isTintable, 'colors' => $colors]);
    }
}
