<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Document;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\RequirementCardProjector;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
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

/** Скачать заполненный бланк карточки (xlsx) по (человек × требование): печать → подпись → приложить скан. */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/card',
    name: 'app_cabinet_compliance_card_download',
    methods: ['GET'],
)]
final class DownloadCardAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
        private readonly RequirementRepositoryInterface $requirements,
        private readonly RequirementCardProjector $projector,
        private readonly TemplateRendering $rendering,
        private readonly ComplianceAccessControl $access,
        private readonly string $cardTemplatePath,
    ) {
    }

    public function __invoke(string $profileId, string $requirementId): Response
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($profileId)
            ?? throw $this->createNotFoundException('Учёт по сотруднику не создан.');
        $requirement = $this->requirements->findOneById($requirementId)
            ?? throw $this->createNotFoundException('Требование не найдено.');
        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $profileResult->profile) {
            throw $this->createNotFoundException('Профиль не найден.');
        }

        $data = $this->projector->project($profileCompliance, $profileResult->profile, $requirement, new \DateTimeImmutable());
        $doc = $this->rendering->render(new TemplateFile($this->cardTemplatePath), $data);

        $name = 'Карточка-'.$requirement->getName().'.'.$doc->extension();
        $response = new Response($doc->content, Response::HTTP_OK, ['Content-Type' => $doc->mimeType()]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            'card.'.$doc->extension(),
        ));

        return $response;
    }
}
