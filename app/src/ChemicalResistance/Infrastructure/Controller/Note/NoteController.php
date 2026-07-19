<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Note;

use App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote\CreateNoteCommand;
use App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote\DeleteNoteCommand;
use App\ChemicalResistance\Application\UseCase\Command\Note\UpdateNote\UpdateNoteCommand;
use App\ChemicalResistance\Application\UseCase\Query\GetPagedNotes\GetPagedNotesQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetNote\GetNoteQuery;
use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/chemical-resistance/note', name: 'app_cabinet_chemical_resistance_note_')]
class NoteController extends AbstractController
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

        $query  = new GetPagedNotesQuery(new NotesFilter($search, Pager::fromPage($page, $limit)));
        $result = $this->queryBus->execute($query);

        return $this->render('admin/chemical_resistance/note/index.html.twig', compact('result'));
    }

    #[Route(path: '', name: 'create')]
    public function create(Request $request): Response
    {
        $inputData = [];

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $command = new CreateNoteCommand(
                    title:       $inputData['title'] ?? '',
                    description: $inputData['description'] ?? '',
                );
                $this->commandBus->execute($command);
                $this->addFlash(
                    'note_created_success',
                    sprintf('Примечание "%s" было добавлено.', $inputData['title'] ?? ''),
                );

                return $this->redirectToRoute('app_cabinet_chemical_resistance_note_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/chemical_resistance/note/form.html.twig', compact('error', 'inputData'));
            }
        }

        return $this->render('admin/chemical_resistance/note/form.html.twig', compact('inputData'));
    }

    #[Route(path: '/{id}/edit', name: 'update', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function update(string $id, Request $request): Response
    {
        $result = $this->queryBus->execute(new GetNoteQuery($id));

        if (null === $result->note) {
            $this->addFlash('note_edited_error', sprintf('Примечание с идентификатором "%s" не найдено.', $id));
            return $this->redirectToRoute('app_cabinet_chemical_resistance_note_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $command = new UpdateNoteCommand(
                    id:          $id,
                    title:       $inputData['title'] ?? '',
                    description: $inputData['description'] ?? '',
                );
                $this->commandBus->execute($command);
                $this->addFlash(
                    'note_updated_success',
                    sprintf('Примечание "%s" было обновлено.', $inputData['title'] ?? ''),
                );

                return $this->redirectToRoute('app_cabinet_chemical_resistance_note_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/chemical_resistance/note/form.html.twig', compact('error', 'inputData'));
            }
        }

        $dto = $result->note;
        $inputData = [
            'id'          => $id,
            'title'       => $dto->title,
            'description' => $dto->description,
        ];

        return $this->render('admin/chemical_resistance/note/form.html.twig', compact('inputData'));
    }

    #[Route(path: '/{id}/delete', name: 'delete', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function delete(string $id): Response
    {
        try {
            $this->commandBus->execute(new DeleteNoteCommand($id));
            $this->addFlash('note_removed_success', 'Примечание удалено.');
        } catch (\Exception|\Error $e) {
            $this->addFlash('note_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_chemical_resistance_note_list');
    }
}
