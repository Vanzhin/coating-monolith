<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance\CreateSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance\DeleteSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Command\Substance\UpdateSubstance\UpdateSubstanceCommand;
use App\ChemicalResistance\Application\UseCase\Query\GetPagedSubstances\GetPagedSubstancesQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetSubstance\GetSubstanceQuery;
use App\ChemicalResistance\Domain\Repository\SubstancesFilter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/chemical-resistance/substance', name: 'app_cabinet_chemical_resistance_substance_')]
class SubstanceController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface   $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {}

    #[Route(path: '/list', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $search = $request->query->get('search');
        $page   = $request->query->get('page')  ? (int) $request->query->get('page')  : null;
        $limit  = $request->query->get('limit') ? (int) $request->query->get('limit') : null;

        $query  = new GetPagedSubstancesQuery(new SubstancesFilter($search, Pager::fromPage($page, $limit)));
        $result = $this->queryBus->execute($query);

        return $this->render('admin/chemical_resistance/substance/index.html.twig', compact('result'));
    }

    #[Route(path: '', name: 'create')]
    public function create(Request $request): Response
    {
        $inputData = [];

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $command = new CreateSubstanceCommand(
                    canonicalName: $inputData['canonicalName'] ?? '',
                    cas:           ($inputData['cas'] ?? '') !== '' ? (string) $inputData['cas'] : null,
                    aliases:       $this->parseAliases((string) ($inputData['aliasesText'] ?? '')),
                );
                $this->commandBus->execute($command);
                $this->addFlash(
                    'substance_created_success',
                    sprintf('Вещество "%s" было добавлено.', $inputData['canonicalName'] ?? ''),
                );

                return $this->redirectToRoute('app_cabinet_chemical_resistance_substance_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/chemical_resistance/substance/form.html.twig', compact('error', 'inputData'));
            }
        }

        return $this->render('admin/chemical_resistance/substance/form.html.twig', compact('inputData'));
    }

    #[Route(path: '/{id}/edit', name: 'update', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function update(string $id, Request $request): Response
    {
        $result = $this->queryBus->execute(new GetSubstanceQuery($id));

        if (null === $result->substance) {
            $this->addFlash('substance_edited_error', sprintf('Вещество с идентификатором "%s" не найдено.', $id));
            return $this->redirectToRoute('app_cabinet_chemical_resistance_substance_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $command = new UpdateSubstanceCommand(
                    id:            $id,
                    canonicalName: $inputData['canonicalName'] ?? '',
                    cas:           ($inputData['cas'] ?? '') !== '' ? (string) $inputData['cas'] : null,
                    aliases:       $this->parseAliases((string) ($inputData['aliasesText'] ?? '')),
                );
                $this->commandBus->execute($command);
                $this->addFlash(
                    'substance_updated_success',
                    sprintf('Вещество "%s" было обновлено.', $inputData['canonicalName'] ?? ''),
                );

                return $this->redirectToRoute('app_cabinet_chemical_resistance_substance_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/chemical_resistance/substance/form.html.twig', compact('error', 'inputData'));
            }
        }

        $dto = $result->substance;
        $inputData = [
            'id'          => $id,
            'canonicalName' => $dto->canonicalName,
            'cas'         => $dto->cas ?? '',
            'aliasesText' => implode("\n", $dto->aliases),
        ];

        return $this->render('admin/chemical_resistance/substance/form.html.twig', compact('inputData'));
    }

    #[Route(path: '/{id}/delete', name: 'delete', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function delete(string $id): Response
    {
        try {
            $this->commandBus->execute(new DeleteSubstanceCommand($id));
            $this->addFlash('substance_removed_success', 'Вещество удалено.');
        } catch (\Exception|\Error $e) {
            $this->addFlash('substance_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_chemical_resistance_substance_list');
    }

    /** @return list<string> */
    private function parseAliases(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text))));
    }
}
