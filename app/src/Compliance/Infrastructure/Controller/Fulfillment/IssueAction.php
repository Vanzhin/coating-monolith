<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
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
 * Карточка требования у человека. Форма ВЫДАЧИ доступна ВСЕГДА (не гейтится статусом нормы): рисует ВСЕ позиции
 * текущей нормы с предзаполнением дефицитом (норма − на руках, не ниже 0), количество переопределяемо — замену
 * и досрочную выдачу можно записать даже при полностью удовлетворённой (Green) норме. «Записать выдачу» пишет
 * факты прямо в журнал ({@see RecordIssuanceCommand}). СПИСАНИЕ — параллельный вход на той же странице (блок
 * «Действующие позиции» + акты): «Списать» ведёт в акт списания ({@see GoToWriteOffAction}), доступно при held > 0.
 */
#[Route(path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/issue', name: 'app_cabinet_compliance_issue', methods: ['GET', 'POST'])]
final class IssueAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
        private readonly RequirementRepositoryInterface $requirements,
    ) {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId): Response
    {
        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $inputData */
            $inputData = $request->getPayload()->all();
            try {
                $this->commandBus->execute(new RecordIssuanceCommand(
                    $profileId,
                    $requirementId,
                    (string) ($inputData['documentDate'] ?? ''),
                    array_values((array) ($inputData['items'] ?? [])),
                    array_values((array) ($inputData['personalItems'] ?? [])),
                    (string) ($inputData['cardNumber'] ?? ''),
                ));
                $this->addFlash('success', 'Выдача записана в журнал.');

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
            // Выдача доступна ВСЕГДА: форма строится из ВСЕХ позиций текущей нормы (а не только дефицитных),
            // поэтому замену/досрочную выдачу можно записать и при полностью удовлетворённой (Green) норме.
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
                // Предзаполнение количества выдачи — дефицитом (норма минус то, что уже на руках, но не ниже 0);
                // при полной норме дефицит = 0, поле остаётся редактируемым (замена сверх нормы).
                'deficitValue' => null !== $row->quantityValue ? AmountFormatter::trimmed(max(0.0, (float) $row->quantityValue - $held)) : null,
                'savedDate' => null, 'savedWear' => null, 'savedDueDate' => null, 'savedUnit' => null, 'savedNote' => null, 'savedInstruction' => [],
            ];
        }

        $actType = $profileCompliance?->typeOfRequirement($requirementId); // тип (мономорфен) для полей строки

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

        // Дефицит — начальное состояние формы, не сохранённая корзина (черновика больше нет): количество уже
        // предзаполнено в issueRows (норма − held). На повторном рендере после ошибки восстанавливаем
        // построчно только из присланного POST ($inputData) — поля инструктажа тоже.
        $submittedItems = (array) ($inputData['items'] ?? []);
        foreach ($issueRows as $i => $issueRow) {
            $submitted = (array) ($submittedItems[$i] ?? []);
            $issueRows[$i]['savedInstruction'] = (array) ($submitted['instruction'] ?? []);
        }
        $documentDate = (string) ($inputData['documentDate'] ?? '') ?: date('Y-m-d');

        // Списание — ПАРАЛЛЕЛЬНЫЙ вход, доступный на той же странице (не взаимоисключающий с выдачей по статусу).
        // Ниже собираем действующие позиции (на руках > 0) и акты списания; «Списать» в шаблоне показываем,
        // когда есть что списывать. «на руках N» — текущий остаток (жёлтым, если часть уже списана).
        $cadenceByKey = [];
        $normByKey = [];
        foreach ($obligations as $o) {
            $cadenceByKey[$o->key] = $o->cadenceLabel;
            $normByKey[$o->key] = null !== $o->quantityValue ? (float) $o->quantityValue : null;
        }

        // Акты списания по требованию; попутно карта списаний по факту recordId → [{№, кол-во}].
        $writeOffActs = [];
        $writeOffsByRecord = [];
        if (null !== $profileCompliance) {
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
                'writeOffNote' => implode('; ', $notes),
            ];
        }

        $heldPositions = [];
        foreach ($positions as $p) {
            $norm = $p['norm'];
            $heldPositions[] = [
                'label' => $p['label'],
                'meta' => trim($p['meta'].(null !== $norm ? ' · норма '.AmountFormatter::trimmed($norm).' '.$p['unit'] : '')),
                'unit' => $p['unit'],
                'held' => AmountFormatter::trimmed($p['held']),
                'norm' => null !== $norm ? AmountFormatter::trimmed($norm) : null,
                'tone' => $this->heldTone($p['held'], $norm),
                'facts' => $p['facts'],
            ];
        }

        return $this->render('admin/compliance/person/issue.html.twig', [
            'profileId' => $profileId,
            'requirementId' => $requirementId,
            'requirementName' => $requirementName,
            'profile' => $profileResult->profile,
            // № карточки СИЗ — свойство журнала (одна карточка на человека): уже закреплён → форма покажет
            // readonly; не закреплён → редактируемое поле, предзаполненное табельным (profile.personnelNumber).
            'cardNumber' => $profileCompliance?->cardNumber(),
            'rows' => $issueRows,
            'requirementType' => null !== $actType ? $actType->value : 'material',
            'instructionGroups' => $instructionGroups,
            'inputData' => $inputData,
            'documentDate' => $documentDate,
            'error' => $error,
            'units' => array_map(static fn (Unit $u): array => ['value' => $u->value, 'title' => $u->title()], Unit::cases()),
            // Параллельный блок списания на той же странице.
            'heldPositions' => $heldPositions,
            'writeOffActs' => $writeOffActs,
        ]);
    }
}
