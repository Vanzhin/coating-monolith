<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\Service\CoatingSystemFormRehydrator;
use App\Coatings\Application\Service\GeneralTagsJsonHydrator;
use App\Coatings\Application\UseCase\Command\ReplaceLayers\ReplaceLayersCommand;
use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommand;
use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Mapper\CoatingSystemMapper;
use App\Coatings\Infrastructure\Validation\CoatingSystemErrorFormatter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating-system/{id}/update', name: 'app_cabinet_coating_system_update', requirements: ['id' => '[0-9a-f-]{36}'])]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly CoatingSystemMapper $mapper,
        private readonly CoatingSystemErrorFormatter $errorFormatter,
        private readonly GeneralTagsJsonHydrator $tagsHydrator,
        private readonly CoatingSystemFormRehydrator $rehydrator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $dto = $this->queryBus->execute(new FindCoatingSystemByIdQuery($id));
        if (null === $dto) {
            $this->addFlash('coating_system_error', sprintf('Система покрытий "%s" не найдена.', $id));

            return $this->redirectToRoute('app_cabinet_coating_system_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = [];
            try {
                $inputData = $request->getPayload()->all();
                // Слои приходят как list<{coatingId, dft}> в порядке DOM. Валидируем
                // их вместе с остальной формой — getValidationCollection() покрывает
                // 'layers' узлом Assert\Uuid()/Assert\Positive(), иначе кривой
                // coatingId долетает до репозитория и падает сырым Doctrine-исключением.
                $errors = $this->validator->validate($inputData, $this->mapper->getValidationCollection());
                if ($errors) {
                    throw new AppException($this->errorFormatter->format($errors));
                }
                /** @var UpdateCoatingSystemMetadataCommand $command */
                $command = $this->mapper->buildCommandFromInputData($inputData, $id);
                $this->commandBus->execute($command);

                $this->commandBus->execute(new ReplaceLayersCommand(
                    $id,
                    $this->mapper->layersFromInput((array) ($inputData['layers'] ?? [])),
                ));

                $this->addFlash('coating_system_updated_success', sprintf('Система покрытий "%s" обновлена.', $command->title));

                return $this->redirectToRoute('app_cabinet_coating_system_list');
            } catch (\Exception $e) {
                $error = $e->getMessage();
                $inputData = $this->rehydrator->enrichInputDataWithTitles($inputData);
                $rawTagIds = array_map(
                    static fn (string $tagId) => ['id' => $tagId],
                    (array) ($inputData['tagIds'] ?? []),
                );
                // Перечитываем DTO — состояние могло измениться до исключения.
                $freshDto = $this->queryBus->execute(new FindCoatingSystemByIdQuery($id)) ?? $dto;

                return $this->render('cabinet/coating/coating_system/form.html.twig', [
                    'error' => $error,
                    'inputData' => $inputData,
                    'systemId' => $id,
                    'substrates' => Substrate::cases(),
                    'existingTagsJson' => $this->tagsHydrator->hydrateAsJson($rawTagIds),
                    'layersDto' => $freshDto,
                ]);
            }
        }

        $inputData = $this->mapper->buildInputDataFromDto($dto);

        return $this->render('cabinet/coating/coating_system/form.html.twig', [
            'inputData' => $inputData,
            'systemId' => $id,
            'substrates' => Substrate::cases(),
            'existingTagsJson' => $this->tagsHydrator->hydrateAsJson($dto->tags),
            'layersDto' => $dto,
        ]);
    }
}
