<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\Service\ComplianceActViewBuilder;
use App\Compliance\Application\UseCase\Command\SaveDraft\SaveDraftCommand;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
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
 *  - открытый черновик → ОФОРМЛЕНИЕ (заполнить позиции; «Сохранить черновик» или «Оформить» + скан → SaveDraft);
 *  - подписан (финал) → СПИСАНИЕ (поля позиций заблокированы, чекбокс + причина → «Списать выбранное»).
 */
#[Route(path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/issue', name: 'app_cabinet_compliance_issue', methods: ['GET', 'POST'])]
#[Route(path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/issue/act/{documentId}', name: 'app_cabinet_compliance_act_show', methods: ['GET'])]
final class IssueAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
        private readonly ComplianceActViewBuilder $actViewBuilder,
        private readonly RequirementRepositoryInterface $requirements,
    ) {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId, ?string $documentId = null): Response
    {
        if (null !== $documentId) {
            return $this->renderAct($profileId, $requirementId, $documentId);
        }
        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $inputData */
            $inputData = $request->getPayload()->all();
            // «Оформить» (скан + подпись) либо «Сохранить черновик» (без скана). Сам скан решает в домене, но
            // кнопкой фиксируем намерение: при сохранении скан игнорируем, даже если был прикреплён.
            $isSign = 'sign' === ($inputData['action'] ?? 'sign');
            try {
                $this->commandBus->execute(new SaveDraftCommand(
                    $profileId,
                    (string) ($inputData['draftId'] ?? ''),
                    (string) ($inputData['documentDate'] ?? ''),
                    array_values((array) ($inputData['items'] ?? [])),
                    (string) ($inputData['cardNumber'] ?? ''),
                    (string) ($inputData['responsibleFio'] ?? ''),
                    $isSign ? (((string) ($inputData['stagedFileId'] ?? '')) ?: null) : null, // скан прикладываем только при «Оформить»
                    array_values((array) ($inputData['personalItems'] ?? [])),
                    sign: $isSign, // «Оформить» → подпись (скан обязателен, инвариант в домене); «Сохранить» → только корзина
                ));
                $this->addFlash('success', $isSign ? 'Карточка оформлена и стала действующей.' : 'Черновик сохранён.');

                return $isSign
                    ? $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId])
                    : $this->redirectToRoute('app_cabinet_compliance_issue', ['profileId' => $profileId, 'requirementId' => $requirementId]);
            } catch (AppException $e) {
                return $this->renderForm($profileId, $requirementId, $e->getMessage(), $inputData);
            }
        }

        return $this->renderForm($profileId, $requirementId, null, []);
    }

    /**
     * Просмотр КОНКРЕТНОГО подписанного акта выдачи (слепок): реквизиты + позиции именно этого документа
     * (что выдано: наименование/модель/кол-во/дата + на руках/списание по его записям). Read-only.
     */
    private function renderAct(string $profileId, string $requirementId, string $documentId): Response
    {
        $view = $this->actViewBuilder->build($profileId, $requirementId, $documentId);
        if (null === $view) {
            $this->addFlash('warning', 'Подписанный акт не найден.');

            return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
        }

        // Тип требования (мономорфен) — чтобы в акте процедуры не показывать списание (его у не материального нет).
        $actType = $this->repository->findByProfile($profileId)?->typeOfRequirement($requirementId);

        return $this->render('admin/compliance/person/issue.html.twig', [
            'mode' => 'act',
            'profileId' => $profileId,
            'requirementId' => $requirementId,
            'documentId' => $documentId,
            'requirementName' => $view['requirementName'],
            'profile' => $view['profile'],
            'rows' => $view['rows'],
            'act' => $view['act'],
            'requirementType' => null !== $actType ? $actType->value : 'material',
        ]);
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
                    'origin' => $row->origin, // personal (вне нормы) → в форме можно удалить из черновика
                    // Предзаполнение количества выдачи — дефицитом (норма минус то, что уже на руках).
                    'deficitValue' => null !== $row->quantityValue ? AmountFormatter::trimmed(max(0.0, (float) $row->quantityValue - $held)) : null,
                    'savedDate' => null, 'savedWear' => null, 'savedDueDate' => null, 'savedUnit' => null, 'savedNote' => null, 'savedInstruction' => [], // из корзины ниже
                ];
            }
        }

        // Режим: открытый черновик → оформление; иначе есть подписанный акт → списание.
        if (null !== $openDraft) {
            $actType = $profileCompliance->typeOfRequirement($requirementId); // тип акта (мономорфен) для полей персональной строки

            // Схема полей инструктажа (для не материального требования с видом журнала) — форма рисует поля по ней,
            // сгруппировав по FieldSpec::group (напр. «Теоретическая часть» / «Практическая часть»).
            $journalKind = $this->requirements->findOneById($requirementId)?->getJournalKind();
            $instructionGroups = [];
            foreach (null !== $journalKind ? $journalKind->fields() : [] as $field) {
                $fieldView = ['key' => $field->key, 'label' => $field->label, 'kind' => $field->kind->value, 'required' => $field->required, 'options' => $field->options];
                $last = array_key_last($instructionGroups);
                if (null !== $last && $instructionGroups[$last]['label'] === $field->group) {
                    $instructionGroups[$last]['fields'][] = $fieldView;
                } else {
                    $instructionGroups[] = ['label' => $field->group, 'fields' => [$fieldView]];
                }
            }

            // Гидрация из сохранённого черновика при GET (на reload inputData из POST пуст): реквизиты акта +
            // количества строк берём из корзины, чтобы перезагрузка показывала сохранённое, а не только дефицит.
            $inputData['cardNumber'] ??= $openDraft->actNumber() ?? '';
            $inputData['responsibleFio'] ??= $openDraft->responsibleFio() ?? '';
            // Состояние строки из корзины по ключу (кол-во/дата/износ/срок/единица) — чтобы GET-перезагрузка
            // показывала сохранённое построчно, а не только документную дату и дефицит.
            $cart = [];
            $savedDate = null; // дата документа из сохранённой корзины — иначе «Оформить» после reload запишет сегодня
            foreach ($profileCompliance->recordsForRequirement($requirementId) as $record) {
                if ($record->documentId() !== $openDraft->getId()) {
                    continue;
                }
                $savedDate ??= $record->fulfilledAt();
                $key = $record->obligationKey();
                $cart[$key] ??= ['qty' => 0.0, 'hasQty' => false, 'date' => null, 'wear' => null, 'due' => null, 'unit' => null, 'note' => null, 'instruction' => []];
                $cart[$key]['date'] ??= $record->fulfilledAt()->format('Y-m-d');
                $cart[$key]['due'] ??= $record->manualDueDate()?->format('Y-m-d');
                $cart[$key]['wear'] ??= null !== $record->wearPercent() ? (string) $record->wearPercent()->value() : null;
                $cart[$key]['note'] ??= $record->note();
                if ([] === $cart[$key]['instruction'] && null !== $record->instructionDetails()) {
                    $cart[$key]['instruction'] = $record->instructionDetails()->values;
                }
                if (null !== $record->quantity()) {
                    $cart[$key]['qty'] += $record->quantity()->amount;
                    $cart[$key]['hasQty'] = true;
                    $cart[$key]['unit'] ??= $record->quantity()->unit->value;
                }
            }
            foreach ($issueRows as $i => $issueRow) {
                $saved = $cart[$issueRow['key']] ?? null;
                if (null === $saved) {
                    continue;
                }
                if ($saved['hasQty']) {
                    $issueRows[$i]['deficitValue'] = AmountFormatter::trimmed($saved['qty']);
                }
                $issueRows[$i]['savedDate'] = $saved['date'];
                $issueRows[$i]['savedWear'] = $saved['wear'];
                $issueRows[$i]['savedDueDate'] = $saved['due'];
                $issueRows[$i]['savedUnit'] = $saved['unit'];
                $issueRows[$i]['savedNote'] = $saved['note'];
                $issueRows[$i]['savedInstruction'] = $saved['instruction'] ?? [];
            }
            $documentDate = (string) ($inputData['documentDate'] ?? '') ?: ($savedDate?->format('Y-m-d') ?? date('Y-m-d'));

            return $this->render('admin/compliance/person/issue.html.twig', [
                'mode' => 'issue',
                'profileId' => $profileId,
                'requirementId' => $requirementId,
                'requirementName' => $requirementName,
                'profile' => $profileResult->profile,
                'draftId' => $openDraft->getId(),
                'rows' => $issueRows,
                'requirementType' => null !== $actType ? $actType->value : 'material',
                'instructionGroups' => $instructionGroups,
                'inputData' => $inputData,
                'documentDate' => $documentDate,
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
