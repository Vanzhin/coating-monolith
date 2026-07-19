<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Note;

use App\ChemicalResistance\Application\UseCase\Command\Note\UpdateNote\UpdateNoteCommand;
use App\ChemicalResistance\Application\UseCase\Query\GetNote\GetNoteQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/chemical-resistance/note/{id}/edit',
    name: 'app_cabinet_chemical_resistance_note_update',
    requirements: ['id' => '[0-9a-f-]{36}'],
)]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $result = $this->queryBus->execute(new GetNoteQuery($id));
        if ($result->note === null) {
            $this->addFlash('note_edited_error', sprintf('Примечание с идентификатором «%s» не найдено.', $id));
            return $this->redirectToRoute('app_cabinet_chemical_resistance_note_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $this->commandBus->execute(new UpdateNoteCommand(
                    id:          $id,
                    title:       (string) ($inputData['title'] ?? ''),
                    description: (string) ($inputData['description'] ?? ''),
                ));
                $this->addFlash(
                    'note_updated_success',
                    sprintf('Примечание «%s» было обновлено.', $inputData['title'] ?? ''),
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

        $dto = $result->note;
        $inputData = [
            'id'          => $id,
            'title'       => $dto->title,
            'description' => $dto->description,
        ];

        return $this->render('admin/chemical_resistance/note/form.html.twig', compact('inputData'));
    }
}
