<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\WriteOffActProjector;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
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

/** Скачать акт списания (.docx) для печати и подписания комиссией. */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/writeoff/{actId}/download',
    name: 'app_cabinet_compliance_writeoff_download',
    methods: ['GET'],
)]
final class DownloadAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
        private readonly WriteOffActProjector $projector,
        private readonly TemplateRendering $rendering,
        private readonly ComplianceAccessControl $access,
        private readonly string $writeOffTemplatePath,
    ) {
    }

    public function __invoke(string $profileId, string $actId): Response
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($profileId)
            ?? throw $this->createNotFoundException('Учёт по сотруднику не создан.');

        $act = null;
        foreach ($profileCompliance->getWriteOffActs() as $candidate) {
            if ($candidate->getId() === $actId) {
                $act = $candidate;
                break;
            }
        }
        if (null === $act) {
            throw $this->createNotFoundException('Акт списания не найден.');
        }

        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $profileResult->profile) {
            throw $this->createNotFoundException('Профиль не найден.');
        }

        $doc = $this->rendering->render(new TemplateFile($this->writeOffTemplatePath), $this->projector->project($act, $profileResult->profile));

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
