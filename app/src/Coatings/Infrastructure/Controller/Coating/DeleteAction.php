<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Command\RemoveCoating\RemoveCoatingCommand;
use App\Shared\Infrastructure\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/{id}/delete', name: 'app_cabinet_coating_coating_delete')]
class DeleteAction extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    )
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $command = new RemoveCoatingCommand($id);
        $this->commandBus->execute($command);
        $this->addFlash('coating_removed_success', 'Покрытие удалено.');

        return $this->redirectToRoute('app_cabinet_coating_coating_list');
    }
}