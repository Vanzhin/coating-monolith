<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating-system/{id}', name: 'app_cabinet_coating_system_view', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
class ViewAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $dto = $this->queryBus->execute(new FindCoatingSystemByIdQuery($id));
        if (null === $dto) {
            $this->addFlash('coating_system_error', sprintf('Система покрытий "%s" не найдена.', $id));

            return $this->redirectToRoute('app_cabinet_coating_system_list');
        }

        // Группируем compliance по standard
        $complianceByStandard = [];
        foreach ($dto->compliance as $entry) {
            $standard = $entry['standard'];
            if (!isset($complianceByStandard[$standard])) {
                $complianceByStandard[$standard] = [
                    'standardTitle' => $entry['standardTitle'],
                    'entries' => [],
                ];
            }
            $complianceByStandard[$standard]['entries'][] = $entry;
        }

        return $this->render('cabinet/coating/coating_system/view.html.twig', [
            'system' => $dto,
            'complianceByStandard' => $complianceByStandard,
        ]);
    }
}
