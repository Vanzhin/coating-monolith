<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Command\CreateManufacturer\CreateManufacturerCommand;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/{id}/edit', name: 'app_cabinet_coating_coating_update')]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    )
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        dd(self::class);
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
}