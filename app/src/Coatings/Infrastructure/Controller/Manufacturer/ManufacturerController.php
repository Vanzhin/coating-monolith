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
        private readonly QueryBusInterface   $queryBus,
        private readonly CommandBusInterface $commandBus,
    )
    {
    }

    #[Route(path: '/list', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $search = $request->query->get('search');
        $page = $request->query->get('page') ? (int)$request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int)$request->query->get('limit') : null;
        $query = new GetPagedManufacturersQuery(new ManufacturersFilter($search, Pager::fromPage($page, $limit)));
        $result = $this->queryBus->execute($query);
        return $this->render('admin/coating/manufacturer/index.html.twig', compact('result'));
    }

    #[Route(path: '', name: 'create')]
    public function create(Request $request): Response
    {
        $error = null;
        $title = null;
        $description = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                $title = $request->getPayload()->get('title');
                $description = $request->getPayload()->get('description');
                $command = new CreateManufacturerCommand($title, $description);
                $this->commandBus->execute($command);
                $this->addFlash('manufacturer_created_success', sprintf('Производитель "%s" был добавлен.', $title));

                return $this->redirectToRoute('app_cabinet_coating_manufacturer_list', compact('error'));
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/coating/manufacturer/create.html.twig', compact('error', 'title', 'description'));
            }
        }

        return $this->render('admin/coating/manufacturer/create.html.twig', compact('error', 'title', 'description'));
    }

    #[Route(path: '/{id}/edit', name: 'update')]
    public function update(Request $request, string $id): Response
    {
        $error = null;
        $query = new GetManufacturerQuery($id);
        $result = $this->queryBus->execute($query);
        if (null === $result->manufacturer) {
            $this->addFlash('manufacturer_edited_error', sprintf('Производитель с идентификатором "%s" не найден.', $id));
            return $this->redirectToRoute('app_cabinet_coating_manufacturer_list', compact('error'));
        }
        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                $title = $request->getPayload()->get('title');
                $description = $request->getPayload()->get('description');
                $result->manufacturer->title = $title;
                $result->manufacturer->description = $description;
                $command = new UpdateManufacturerCommand($id, $result->manufacturer);
                $result = $this->commandBus->execute($command);
                $this->addFlash('manufacturer_updated_success', sprintf('Производитель "%s" был обновлен.', $title));

                return $this->redirectToRoute('app_cabinet_coating_manufacturer_list', compact('error'));
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/coating/manufacturer/edit.html.twig', compact('error', 'result'));
            }
        }

        return $this->render('admin/coating/manufacturer/edit.html.twig', compact('error', 'result'));
    }

    #[Route(path: '/{id}/delete', name: 'delete')]
    public function delete(string $id): Response
    {
        $error = null;
        try {
            $command = new RemoveManufacturerCommand($id);
            $result = $this->commandBus->execute($command);
            $this->addFlash('manufacturer_removed_success', 'Производитель удален.');
        } catch (\Exception|\Error $e) {
            $error = $e->getMessage();
        }
        return $this->redirectToRoute('app_cabinet_coating_manufacturer_list', compact('error'));
    }
}