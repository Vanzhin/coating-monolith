<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\Service\GeneralTagsJsonHydrator;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
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
use Symfony\Component\Uid\Uuid;

#[Route(path: '/cabinet/coating/coating-system/add', name: 'app_cabinet_coating_system_add')]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly Validator $validator,
        private readonly CoatingSystemMapper $mapper,
        private readonly CoatingRepositoryInterface $coatingRepository,
        private readonly CoatingSystemErrorFormatter $errorFormatter,
        private readonly GeneralTagsJsonHydrator $tagsHydrator,
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
                $inputData = $this->enrichInputDataWithTitles($inputData);
                $rawTagIds = array_map(
                    static fn (string $id) => ['id' => $id],
                    (array) ($inputData['tagIds'] ?? []),
                );

                return $this->render('cabinet/coating/coating_system/form.html.twig', [
                    'error' => $error,
                    'inputData' => $inputData,
                    'substrates' => Substrate::cases(),
                    'existingTagsJson' => $this->tagsHydrator->hydrateAsJson($rawTagIds),
                ]);
            }
        }

        return $this->render('cabinet/coating/coating_system/form.html.twig', [
            'inputData' => null,
            'substrates' => Substrate::cases(),
            'existingTagsJson' => '[]',
        ]);
    }

    /**
     * После POST-ошибки обогащаем inputData заголовками treatment и coatings,
     * чтобы async-typeahead мог восстановить preselected-теги.
     *
     * @param array<string, mixed> $inputData
     *
     * @return array<string, mixed>
     */
    private function enrichInputDataWithTitles(array $inputData): array
    {
        // Заголовок выбранной подготовки поверхности
        $treatmentId = $inputData['surfaceTreatmentId'] ?? null;
        if (is_string($treatmentId) && '' !== $treatmentId && Uuid::isValid($treatmentId)) {
            $treatmentDto = $this->queryBus->execute(new FindSurfaceTreatmentByIdQuery($treatmentId));
            if (null !== $treatmentDto) {
                $inputData['surfaceTreatmentTitle'] = $treatmentDto->title;
            }
        }

        // Заголовки покрытий в слоях
        $layerCoatingIds = [];
        foreach ($inputData['layers'] ?? [] as $layer) {
            $cid = $layer['coatingId'] ?? null;
            if (is_string($cid) && Uuid::isValid($cid)) {
                $layerCoatingIds[] = $cid;
            }
        }

        $coatingTitlesById = [];
        if ([] !== $layerCoatingIds) {
            foreach ($this->coatingRepository->findByIds($layerCoatingIds) as $coating) {
                $dft = $coating->getDftRange();
                $coatingTitlesById[$coating->getId()] = sprintf(
                    '%s (%s, %d–%d мкм)',
                    $coating->getTitle(),
                    $coating->getBase()->value,
                    (int) $dft->range->getMin(),
                    (int) $dft->range->getMax(),
                );
            }
        }

        $inputData['coatingTitlesById'] = $coatingTitlesById;

        return $inputData;
    }
}
