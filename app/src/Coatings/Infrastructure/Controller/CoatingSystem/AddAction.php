<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\Service\CoatingSystemFormRehydrator;
use App\Coatings\Application\Service\GeneralTagsJsonHydrator;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Mapper\CoatingSystemMapper;
use App\Coatings\Infrastructure\Validation\CoatingSystemErrorFormatter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating-system/add', name: 'app_cabinet_coating_system_add')]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly CoatingSystemMapper $mapper,
        private readonly CoatingSystemErrorFormatter $errorFormatter,
        private readonly GeneralTagsJsonHydrator $tagsHydrator,
        private readonly CoatingSystemFormRehydrator $rehydrator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = [];
            try {
                $inputData = $request->getPayload()->all();
                $errors = $this->validator->validate($inputData, $this->mapper->getValidationCollection());
                if ($errors) {
                    throw new AppException($this->errorFormatter->format($errors));
                }
                /** @var CreateCoatingSystemCommand $command */
                $command = $this->mapper->buildCommandFromInputData($inputData);
                $this->commandBus->execute($command);
                $this->addFlash('coating_system_created_success', sprintf('Система покрытий "%s" добавлена.', $command->title));

                return $this->redirectToRoute('app_cabinet_coating_system_list');
            } catch (\Exception $e) {
                $error = $e->getMessage();
                $inputData = $this->rehydrator->enrichInputDataWithTitles($inputData);
                $rawTagIds = array_map(
                    static fn (string $id) => ['id' => $id],
                    (array) ($inputData['tagIds'] ?? []),
                );

                return $this->render('cabinet/coating/coating_system/form.html.twig', [
                    'error' => $error,
                    'inputData' => $inputData,
                    'substrates' => Substrate::cases(),
                    'environments' => EnvironmentType::cases(),
                    'existingTagsJson' => $this->tagsHydrator->hydrateAsJson($rawTagIds),
                ]);
            }
        }

        return $this->render('cabinet/coating/coating_system/form.html.twig', [
            'inputData' => null,
            'substrates' => Substrate::cases(),
            'environments' => EnvironmentType::cases(),
            'existingTagsJson' => '[]',
        ]);
    }
}
