<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Controller\Coating;

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

#[Route(path: '/cabinet/coating/coating', name: 'app_cabinet_coating_coating_create')]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,

    )
    {
    }

    public function __invoke(Request $request): Response
    {
        $query = new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000)));
        $pagedManufacturers = $this->queryBus->execute($query);

        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                dd($request->getPayload()->all());
                $title = $request->getPayload()->get('title');
                $description = $request->getPayload()->get('description');
                $command = new CreateManufacturerCommand($title, $description);
                $this->commandBus->execute($command);
                $this->addFlash('manufacturer_created_success', sprintf('Покрытие "%s" добавлено.', $title));

                return $this->redirectToRoute('app_cabinet_coating_manufacturer_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/coating/coating/create.html.twig', compact('error'));
            }
        }

        return $this->render('admin/coating/coating/create.html.twig', compact('pagedManufacturers'));
    }
}