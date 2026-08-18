<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Фрагмент модалки предпросмотра покрытия по id. Кормит Stimulus на вьюхе систем:
 * клик по слою фетчит этот HTML и показывает полную модалку покрытия поверх.
 * Разметка — единый партиал _coating_preview.html.twig (тот же, что инлайном
 * на вьюхе покрытий), никакого дублирования.
 */
#[Route(
    path: '/cabinet/coating/coating/{id}/preview',
    name: 'app_cabinet_coating_coating_preview',
    methods: ['GET'],
)]
final class PreviewAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        /** @var GetCoatingQueryResult $result */
        $result = $this->queryBus->execute(new GetCoatingQuery($id));
        if (null === $result->coatingDTO) {
            throw $this->createNotFoundException(sprintf('Coating with id "%s" not found.', $id));
        }

        return $this->render('admin/coating/coating/_coating_preview.html.twig', [
            'coating' => $result->coatingDTO,
            'canEdit' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }
}
