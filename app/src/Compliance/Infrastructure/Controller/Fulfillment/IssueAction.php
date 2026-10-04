<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\SignDraft\SignDraftCommand;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\ValueObject\Unit;
use App\Compliance\Infrastructure\Controller\AmountFormatter;
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
                    (string) ($inputData['cardNumber'] ?? ''),
                    (string) ($inputData['responsibleFio'] ?? ''),
                    ((string) ($inputData['stagedFileId'] ?? '')) ?: null,
                    array_values((array) ($inputData['personalItems'] ?? [])),
                ));
                $this->addFlash('success', 'Карточка оформлена и стала действующей.');

                return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
            } catch (AppException $e) {
                return $this->renderForm($profileId, $requirementId, $e->getMessage(), $inputData);
            }
        }

        return $this->renderForm($profileId, $requirementId, null, []);
    }

    /** Цвет чипа «на руках» относительно нормы (как подсветка кол-ва в оформлении): меньше — danger, ровно — success, больше — info. */
    private function heldTone(float $held, ?float $norm): string
    {
        if (null === $norm) {
            return 'secondary';
        }
        if ($held < $norm - 1e-9) {
            return 'danger';
        }
        if ($held > $norm + 1e-9) {
            return 'info';
        }

        return 'success';
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
        $issueRows = [];
        foreach ($obligations as $row) {
            if ($row->requirementId !== $requirementId) {
                continue;
            }
            $requirementName = $row->requirementName;
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
                    'deficitValue' => null !== $row->quantityValue ? AmountFormatter::trimmed(max(0.0, (float) $row->quantityValue - $held)) : null,
                ];
            }
        }

        // Режим: открытый черновик → оформление; иначе есть подписанный акт → списание.
        if (null !== $openDraft) {
            $actType = $profileCompliance->typeOfRequirement($requirementId); // тип акта (мономорфен) для полей персональной строки

            return $this->render('admin/compliance/person/issue.html.twig', [
                'mode' => 'issue',
                'profileId' => $profileId,
                'requirementId' => $requirementId,
                'requirementName' => $requirementName,
                'profile' => $profileResult->profile,
                'draftId' => $openDraft->getId(),
                'rows' => $issueRows,
                'requirementType' => null !== $actType ? $actType->value : 'material',
                'inputData' => $inputData,
                'documentDate' => (string) ($inputData['documentDate'] ?? '') ?: date('Y-m-d'),
                'error' => $error,
                'units' => array_map(static fn (Unit $u): array => ['value' => $u->value, 'title' => $u->title()], Unit::cases()),
            ]);
        }

        // Корзина (открытый черновик акта списания) — саму помечаем кнопкой «Перейти».
        $openBasketId = $profileCompliance?->openWriteOffDraftFor($requirementId)?->getId();

        // Сформированный акт: та же форма, что при оформлении, но поля заблокированы и показывают ВЫДАННОЕ
        // (по фактам). «на руках N» — текущий остаток (жёлтым, если часть уже списана). Списание — кнопкой.
        $cadenceByKey = [];
        $normByKey = [];
        foreach ($obligations as $o) {
            $cadenceByKey[$o->key] = $o->cadenceLabel;
            $normByKey[$o->key] = null !== $o->quantityValue ? (float) $o->quantityValue : null;
        }

        // Акты: подписанные карточки выдачи + акты списания; попутно карта списаний по факту recordId → [{№, кол-во}].
        $signedActs = [];
        $writeOffActs = [];
        $writeOffsByRecord = [];
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
                    if ($act->isSigned()) {
                        $writeOffsByRecord[$portion->recordId()][] = ['actNumber' => $act->actNumber(), 'qty' => $portion->quantity()];
                    }
                }
                $writeOffActs[] = ['id' => $act->getId(), 'signed' => $act->isSigned(), 'actNumber' => $act->actNumber(), 'items' => $items];
            }
        }

        // Действующие позиции — ПО ПОЗИЦИИ (obligationKey): «на руках» = Σ held фактов, светофор по сумме против
        // нормы (а не по отдельному факту), разбивка по актам выдачи — в развороте.
        $positions = [];
        foreach ($profileCompliance?->recordsForRequirement($requirementId) ?? [] as $record) {
            if (null === $record->quantity() || $record->heldAmount() <= 0.0) {
                continue; // нематериальный факт или полностью списан
            }
            $key = $record->obligationKey();
            $positions[$key] ??= [
                'label' => $profileCompliance?->obligationLabelOf($key),
                'meta' => $cadenceByKey[$key] ?? '',
                'unit' => $record->quantity()->unit->title(),
                'norm' => $normByKey[$key] ?? null,
                'held' => 0.0,
                'facts' => [],
            ];
            $held = $record->heldAmount();
            $positions[$key]['held'] += $held;
            $notes = [];
            foreach ($writeOffsByRecord[$record->getId()] ?? [] as $w) {
                $notes[] = 'списано '.AmountFormatter::trimmed($w['qty']).(null !== $w['actNumber'] && '' !== $w['actNumber'] ? ' (акт № '.$w['actNumber'].')' : '');
            }
            $positions[$key]['facts'][] = [
                'date' => $record->fulfilledAt()->format('d.m.Y'),
                'held' => AmountFormatter::trimmed($held),
                'documentId' => $record->documentId(),
                'writeOffNote' => implode('; ', $notes),
            ];
        }

        $rows = [];
        foreach ($positions as $p) {
            $norm = $p['norm'];
            $rows[] = [
                'label' => $p['label'],
                'meta' => trim($p['meta'].(null !== $norm ? ' · норма '.AmountFormatter::trimmed($norm).' '.$p['unit'] : '')),
                'unit' => $p['unit'],
                'held' => AmountFormatter::trimmed($p['held']),
                'norm' => null !== $norm ? AmountFormatter::trimmed($norm) : null,
                'tone' => $this->heldTone($p['held'], $norm),
                'facts' => $p['facts'],
            ];
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
}
