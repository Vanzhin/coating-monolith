<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Manufacturer;

use App\Coatings\Application\UseCase\Command\CreateManufacturer\CreateManufacturerCommand;
use App\Coatings\Application\UseCase\Command\RemoveManufacturer\RemoveManufacturerCommand;
use App\Coatings\Application\UseCase\Command\UpdateManufacturer\UpdateManufacturerCommand;
use App\Coatings\Application\UseCase\Query\GetManufacturer\GetManufacturerQuery;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/manufacturer', name: 'app_cabinet_coating_manufacturer_')]
class ManufacturerController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/list', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $search = $request->query->get('search');
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int) $request->query->get('limit') : null;
        $query = new GetPagedManufacturersQuery(new ManufacturersFilter($search, Pager::fromPage($page, $limit)));
        $result = $this->queryBus->execute($query);

        return $this->render('admin/coating/manufacturer/index.html.twig', compact('result'));
    }

    #[Route(path: '', name: 'create')]
    public function create(Request $request): Response
    {
        $inputData = [];
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $command = new CreateManufacturerCommand($inputData['title'] ?? null, $inputData['description'] ?? null);
                $this->commandBus->execute($command);
                $this->addFlash('manufacturer_created_success', sprintf('Производитель "%s" был добавлен.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_coating_manufacturer_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();

                return $this->render('admin/coating/manufacturer/form.html.twig', compact('error', 'inputData'));
            }
        }

        return $this->render('admin/coating/manufacturer/form.html.twig', compact('inputData'));
    }

    #[Route(path: '/{id}/edit', name: 'update')]
    public function update(Request $request, string $id): Response
    {
        $result = $this->queryBus->execute(new GetManufacturerQuery($id));
        if (null === $result->manufacturer) {
            $this->addFlash('manufacturer_edited_error', sprintf('Производитель с идентификатором "%s" не найден.', $id));

            return $this->redirectToRoute('app_cabinet_coating_manufacturer_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $result->manufacturer->title = $inputData['title'] ?? null;
                $result->manufacturer->description = $inputData['description'] ?? null;
                $this->commandBus->execute(new UpdateManufacturerCommand($id, $result->manufacturer));
                $this->addFlash('manufacturer_updated_success', sprintf('Производитель "%s" был обновлен.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_coating_manufacturer_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();

                return $this->render('admin/coating/manufacturer/form.html.twig', compact('error', 'inputData'));
            }
        }

        $inputData = [
            'id' => $id,
            'title' => $result->manufacturer->title,
            'description' => $result->manufacturer->description,
        ];

        return $this->render('admin/coating/manufacturer/form.html.twig', compact('inputData'));
    }

    #[Route(path: '/{id}/delete', name: 'delete')]
    public function delete(string $id): Response
    {
        try {
            $command = new RemoveManufacturerCommand($id);
            $this->commandBus->execute($command);
            $this->addFlash('manufacturer_removed_success', 'Производитель удален.');
        } catch (\Exception|\Error $e) {
            $error = $e->getMessage();
        }

        return $this->redirectToRoute('app_cabinet_coating_manufacturer_list', compact('error'));
    }
}
