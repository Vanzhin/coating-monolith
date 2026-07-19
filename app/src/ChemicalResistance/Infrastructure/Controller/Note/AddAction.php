<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Note;

use App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote\CreateNoteCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/chemical-resistance/note', name: 'app_cabinet_chemical_resistance_note_create')]
class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus) {}

    public function __invoke(Request $request): Response
    {
        $inputData = [];

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $this->commandBus->execute(new CreateNoteCommand(
                    title:       (string) ($inputData['title'] ?? ''),
                    description: (string) ($inputData['description'] ?? ''),
                ));
                $this->addFlash(
                    'note_created_success',
                    sprintf('Примечание «%s» было добавлено.', $inputData['title'] ?? ''),
                );

                return $this->redirectToRoute('app_cabinet_chemical_resistance_note_list');
            } catch (AppException $e) {
                $error = $e->getMessage();
                return $this->render(
                    'admin/chemical_resistance/note/form.html.twig',
                    compact('error', 'inputData'),
                );
            }
        }

        return $this->render('admin/chemical_resistance/note/form.html.twig', compact('inputData'));
    }
}
