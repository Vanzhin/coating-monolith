<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\WriteOffActProjector;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Shared\Infrastructure\Service\TemplateRendering;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Скачать акт списания (.docx) для печати и подписания комиссией. Акт кросс-персон — резолвим по его id. */
#[Route(
    path: '/cabinet/compliance/writeoff/{actId}/download',
    name: 'app_cabinet_compliance_writeoff_download',
    methods: ['GET'],
)]
final class DownloadAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly WriteOffActRepositoryInterface $writeOffActRepository,
        private readonly ProfileComplianceRepositoryInterface $repository,
        private readonly WriteOffActProjector $projector,
        private readonly TemplateRendering $rendering,
        private readonly ComplianceAccessControl $access,
        private readonly string $writeOffTemplatePath,
    ) {
    }

    public function __invoke(string $actId): Response
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $act = $this->writeOffActRepository->findById($actId)
            ?? throw $this->createNotFoundException('Акт списания не найден.');

        // Акт покрывает позиции нескольких людей одной организации: собираем их профили (шапка) и факты-выдачи
        // (единица/дата строк) по каждому затронутому профилю.
        $profilesById = [];
        $recordsById = [];
        foreach ($act->profileIds() as $profileId) {
            /** @var GetProfileQueryResult $profileResult */
            $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
            if (null !== $profileResult->profile) {
                $profilesById[$profileId] = $profileResult->profile;
            }
            $profileCompliance = $this->repository->findByProfile($profileId);
            foreach (null !== $profileCompliance ? $profileCompliance->getRecords() : [] as $record) {
                $recordsById[$record->getId()] = $record;
            }
        }

        $doc = $this->rendering->render(new TemplateFile($this->writeOffTemplatePath), $this->projector->project($act, $profilesById, $recordsById));

        $name = 'Акт-списания-'.($act->actNumber() ?? 'черновик').'.'.$doc->extension();
        $response = new Response($doc->content, Response::HTTP_OK, ['Content-Type' => $doc->mimeType()]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            'write-off-act.'.$doc->extension(),
        ));

        return $response;
    }
}
