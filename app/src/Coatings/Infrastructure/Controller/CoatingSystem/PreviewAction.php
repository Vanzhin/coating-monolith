<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Фрагмент модалки предпросмотра системы покрытий по id. Кормит Stimulus на форме/списке
 * документов: клик по ссылке-референсу фетчит этот HTML и показывает модалку поверх.
 * Разметка — партиал _coating_system_preview.html.twig (единый серверный источник).
 * Зеркаль Coating\PreviewAction.
 */
#[Route(
    path: '/cabinet/coating/coating-system/{id}/preview',
    name: 'app_cabinet_coating_system_preview',
    methods: ['GET'],
)]
final class PreviewAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        /** @var ?CoatingSystemDTO $dto */
        $dto = $this->queryBus->execute(new FindCoatingSystemByIdQuery($id));
        if (null === $dto) {
            throw $this->createNotFoundException(sprintf('Coating system with id "%s" not found.', $id));
        }

        return $this->render('cabinet/coating/coating_system/_coating_system_preview.html.twig', [
            'system' => $dto,
            'canEdit' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }
}
