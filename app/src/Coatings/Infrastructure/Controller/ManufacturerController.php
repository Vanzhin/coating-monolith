<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Controller;

use App\Coatings\Application\UseCase\Command\CreateManufacturer\CreateManufacturerCommand;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/coating/manufacturer', name: 'app_coating_manufacturer_')]
class ManufacturerController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface   $queryBus,
        private readonly CommandBusInterface $commandBus,
    )
    {
    }

    #[Route(path: '/list', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $search = null;
        $page = 1;
        $perPage = 10;
        $query = new GetPagedManufacturersQuery(new ManufacturersFilter($search, Pager::fromPage($page, $perPage)));
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

                return $this->redirectToRoute('app_coating_manufacturer_list', compact('error'));
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/coating/manufacturer/create.html.twig', compact('error', 'title', 'description'));
            }
        }

        return $this->render('admin/coating/manufacturer/create.html.twig', compact('error', 'title', 'description'));
    }

}