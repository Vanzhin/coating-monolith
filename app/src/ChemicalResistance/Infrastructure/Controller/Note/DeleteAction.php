<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Note;

use App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote\DeleteNoteCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/chemical-resistance/note/{id}/delete',
    name: 'app_cabinet_chemical_resistance_note_delete',
    requirements: ['id' => '[0-9a-f-]{36}'],
)]
class DeleteAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus) {}

    public function __invoke(string $id): Response
    {
        try {
            $this->commandBus->execute(new DeleteNoteCommand($id));
            $this->addFlash('note_removed_success', 'Примечание удалено.');
        } catch (AppException $e) {
            $this->addFlash('note_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_chemical_resistance_note_list');
    }
}
