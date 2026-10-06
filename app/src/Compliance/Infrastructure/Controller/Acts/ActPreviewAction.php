<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Acts;

use App\Compliance\Application\Service\ComplianceActViewBuilder;
use App\Compliance\Domain\Repository\ComplianceActRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Фрагмент-модалка просмотра конкретного акта выдачи (клик по строке списка → entity-preview фетчит и показывает).
 * Профиль/требование резолвятся по documentId. Данные — тем же {@see ComplianceActViewBuilder}, что и страница акта.
 */
#[Route(path: '/cabinet/compliance/act/{documentId}/preview', name: 'app_cabinet_compliance_act_preview', methods: ['GET'])]
final class ActPreviewAction extends AbstractController
{
    public function __construct(
        private readonly ComplianceActRepositoryInterface $acts,
        private readonly ComplianceActViewBuilder $builder,
    ) {
    }

    public function __invoke(string $documentId): Response
    {
        $ref = $this->acts->profileAndRequirementOf($documentId);
        if (null === $ref) {
            throw $this->createNotFoundException('Акт не найден.');
        }
        $view = $this->builder->build($ref['profileId'], $ref['requirementId'], $documentId);
        if (null === $view) {
            throw $this->createNotFoundException('Подписанный акт не найден.');
        }

        return $this->render('admin/compliance/acts/_act_preview.html.twig', [
            'profileId' => $ref['profileId'],
            'requirementId' => $ref['requirementId'],
            'documentId' => $documentId,
            'requirementName' => $view['requirementName'],
            'profile' => $view['profile'],
            'act' => $view['act'],
            'rows' => $view['rows'],
        ]);
    }
}
