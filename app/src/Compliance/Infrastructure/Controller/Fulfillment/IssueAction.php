<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\SignDraft\SignDraftCommand;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\ValueObject\Unit;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Карточка требования у человека — одна форма, два режима по стадии документа:
 *  - открытый черновик → ОФОРМЛЕНИЕ (заполнить подошедшие позиции + скан → «Оформить», SignDraft);
 *  - подписан (финал) → СПИСАНИЕ (поля позиций заблокированы, чекбокс + причина → «Списать выбранное»).
 */
#[Route(path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/issue', name: 'app_cabinet_compliance_issue', methods: ['GET', 'POST'])]
final class IssueAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId): Response
    {
        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $inputData */
            $inputData = $request->getPayload()->all();
            try {
                $this->commandBus->execute(new SignDraftCommand(
                    $profileId,
                    (string) ($inputData['draftId'] ?? ''),
                    (string) ($inputData['documentDate'] ?? ''),
                    array_values((array) ($inputData['items'] ?? [])),
                    ((string) ($inputData['stagedFileId'] ?? '')) ?: null,
                ));
                $this->addFlash('success', 'Карточка оформлена и стала действующей.');

                return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
            } catch (AppException $e) {
                return $this->renderForm($profileId, $requirementId, $e->getMessage(), $inputData);
            }
        }

        return $this->renderForm($profileId, $requirementId, null, []);
    }

    /**
     * @param array<string, mixed> $inputData
     */
    private function renderForm(string $profileId, string $requirementId, ?string $error, array $inputData): Response
    {
        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $profileResult->profile) {
            throw $this->createNotFoundException('Профиль не найден.');
        }
        $profileCompliance = $this->repository->findByProfile($profileId);
        $openDraft = $profileCompliance?->openDraftFor($requirementId);

        /** @var GetProfileComplianceQueryResult $complianceResult */
        $complianceResult = $this->queryBus->execute(new GetProfileComplianceQuery($profileId));
        $obligations = null !== $complianceResult->compliance ? $complianceResult->compliance->obligations : [];

        $requirementName = 'Требование';
        $normByKey = [];
        $issueRows = [];
        foreach ($obligations as $row) {
            if ($row->requirementId !== $requirementId) {
                continue;
            }
            $requirementName = $row->requirementName;
            if (null !== $row->quantityValue) {
                $normByKey[$row->key] = (float) $row->quantityValue;
            }
            if (ComplianceStatus::Green->value !== $row->status) {
                $held = $profileCompliance?->heldOf($row->key) ?? 0.0;
                $issueRows[] = [
                    'key' => $row->key,
                    'label' => $row->label,
                    'type' => $row->type,
                    'cadenceKind' => $row->cadenceKind,
                    'cadenceNumber' => $row->cadenceNumber,
                    'cadenceUnit' => $row->cadenceUnit,
                    'cadenceLabel' => $row->cadenceLabel,
                    'quantityLabel' => $row->quantityLabel,
                    'quantityValue' => $row->quantityValue,
                    'quantityUnit' => $row->quantityUnit,
                    // Предзаполнение количества выдачи — дефицитом (норма минус то, что уже на руках).
                    'deficitValue' => null !== $row->quantityValue ? $this->formatAmount(max(0.0, (float) $row->quantityValue - $held)) : null,
                ];
            }
        }

        // Режим: открытый черновик → оформление; иначе есть подписанный акт → списание.
        if (null !== $openDraft) {
            return $this->render('admin/compliance/person/issue.html.twig', [
                'mode' => 'issue',
                'profileId' => $profileId,
                'requirementId' => $requirementId,
                'requirementName' => $requirementName,
                'profile' => $profileResult->profile,
                'draftId' => $openDraft->getId(),
                'rows' => $issueRows,
                'inputData' => $inputData,
                'documentDate' => (string) ($inputData['documentDate'] ?? '') ?: date('Y-m-d'),
                'error' => $error,
                'units' => array_map(static fn (Unit $u): array => ['value' => $u->value, 'title' => $u->title()], Unit::cases()),
            ]);
        }

        // Корзина (открытый черновик акта списания) — саму помечаем кнопкой «Перейти».
        $openBasketId = $profileCompliance?->openWriteOffDraftFor($requirementId)?->getId();

        // «Списать» — построчно по действующим фактам материальных позиций требования (held > 0).
        $rows = [];
        if (null !== $profileCompliance) {
            foreach ($profileCompliance->getRecords() as $record) {
                $key = $record->obligationKey();
                if (!str_starts_with($key, $requirementId.'|') || null === $record->quantity() || $record->heldAmount() <= 0.0) {
                    continue; // не этого требования, нематериальный факт или уже полностью списан
                }
                $available = $profileCompliance->availableToWriteOff($record->getId());
                $norm = $normByKey[$key] ?? 0.0;
                $rows[] = [
                    'recordId' => $record->getId(),
                    'label' => $profileCompliance->obligationLabelOf($key),
                    'held' => $this->formatAmount($record->heldAmount()),
                    'unit' => $record->quantity()->unit->title(),
                    'available' => $this->formatAmount($available),
                    'inDraftQty' => $this->formatAmount($record->heldAmount() - $available),
                    'deficit' => $this->formatAmount(max(0.0, $norm - $profileCompliance->heldOf($key))),
                ];
            }
        }

        $signedActs = [];
        $writeOffActs = [];
        if (null !== $profileCompliance) {
            foreach ($profileCompliance->signedDocumentsFor($requirementId) as $doc) {
                $signedActs[] = ['documentId' => $doc->getId(), 'signedAt' => $doc->signedAt()?->format('d.m.Y')];
            }
            foreach ($profileCompliance->getWriteOffActs() as $act) {
                if ($act->requirementId() !== $requirementId) {
                    continue;
                }
                $items = [];
                foreach ($profileCompliance->itemsOfWriteOffAct($act->getId()) as $portion) {
                    $fact = $profileCompliance->recordById($portion->recordId());
                    $items[] = [
                        'label' => null !== $fact ? $profileCompliance->obligationLabelOf($fact->obligationKey()) : '',
                        'reason' => $portion->reason()?->title(),
                    ];
                }
                $writeOffActs[] = ['id' => $act->getId(), 'signed' => $act->isSigned(), 'actNumber' => $act->actNumber(), 'items' => $items];
            }
        }

        if ([] === $signedActs && [] === $rows && [] === $writeOffActs) {
            $this->addFlash('warning', 'По требованию нет ни черновика, ни выданных позиций.');

            return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
        }

        return $this->render('admin/compliance/person/issue.html.twig', [
            'mode' => 'writeoff',
            'profileId' => $profileId,
            'requirementId' => $requirementId,
            'requirementName' => $requirementName,
            'profile' => $profileResult->profile,
            'rows' => $rows,
            'openBasketId' => $openBasketId,
            'signedActs' => $signedActs,
            'writeOffActs' => $writeOffActs,
        ]);
    }

    /** Число без хвостового «.0» — для отображения в карточке (как в {@see ProfileComplianceDTOTransformer}). */
    private function formatAmount(float $amount): string
    {
        return 0.0 === fmod($amount, 1.0) ? (string) (int) $amount : (string) $amount;
    }
}
