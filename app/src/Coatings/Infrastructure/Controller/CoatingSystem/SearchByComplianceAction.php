<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystemsByCompliance\SearchCoatingSystemsByComplianceQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoCorrosivityCategory;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoDurability;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/coating-system/search-by-compliance',
    name: 'app_cabinet_coating_system_search_by_compliance',
    methods: ['GET'],
)]
class SearchByComplianceAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $standardRaw = $request->query->get('standard');
        $categoryRaw = $request->query->get('category');
        $durabilityRaw = $request->query->get('durability');
        $substrateRaw = $request->query->get('substrate');

        $items = null;
        $total = 0;
        $error = null;

        $hasParams = null !== $standardRaw && null !== $categoryRaw && null !== $durabilityRaw;

        if ($hasParams) {
            try {
                $standard = ComplianceStandard::from((string) $standardRaw);

                $substrate = null;
                if (is_string($substrateRaw) && '' !== $substrateRaw) {
                    $substrate = Substrate::from($substrateRaw);
                }

                $result = $this->queryBus->execute(new SearchCoatingSystemsByComplianceQuery(
                    standard: $standard,
                    category: (string) $categoryRaw,
                    durability: (string) $durabilityRaw,
                    substrate: $substrate,
                    page: 1,
                    perPage: 50,
                ));

                $items = $result['items'];
                $total = $result['total'];
            } catch (\ValueError) {
                $error = 'Неверный стандарт или субстрат.';
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('cabinet/coating/coating_system/search_by_compliance.html.twig', [
            'items' => $items,
            'total' => $total,
            'error' => $error,
            'standards' => ComplianceStandard::cases(),
            'categories' => IsoCorrosivityCategory::cases(),
            'durabilities' => IsoDurability::cases(),
            'substrates' => Substrate::cases(),
            'standard' => $standardRaw ?? '',
            'category' => $categoryRaw ?? '',
            'durability' => $durabilityRaw ?? '',
            'substrate' => $substrateRaw ?? '',
        ]);
    }
}
