<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Infrastructure\Controller\AmountFormatter;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\ValueObject\CommissionMember;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Страница акта списания: позиции выглядят как в акте выдачи (наименование + на руках), у каждой — количество
 * к списанию и причина. POST: «Сохранить» (op=save — состав акта) или «Оформить» (op=sign — состав + комиссия +
 * №/дата + скан → заморозка и эффект: гашение количества + пересчёт + дефицит-черновик). Права — в хендлерах.
 */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/writeoff/{actId}',
    name: 'app_cabinet_compliance_writeoff_show',
    methods: ['GET', 'POST'],
)]
final class ShowAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(Request $request, string $profileId, string $actId): Response
    {
        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $input */
            $input = $request->getPayload()->all();
            /** @var list<array{recordId: string, quantity: float, reason: string}> $lines */
            $lines = array_values((array) ($input['portions'] ?? []));
            try {
                $this->commandBus->execute(new SaveWriteOffActCommand($profileId, $actId, $lines));
                if ('save' === ($input['op'] ?? 'sign')) {
                    $this->addFlash('success', 'Состав акта списания сохранён.');

                    return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['profileId' => $profileId, 'actId' => $actId]);
                }
                $this->commandBus->execute(new SignWriteOffActCommand(
                    $profileId,
                    $actId,
                    array_values((array) ($input['commission'] ?? [])),
                    (string) ($input['actNumber'] ?? ''),
                    (string) ($input['actDate'] ?? ''),
                    ((string) ($input['stagedFileId'] ?? '')) ?: null,
                ));
                $this->addFlash('success', 'Акт списания оформлен. Позиции списаны.');

                return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['profileId' => $profileId, 'actId' => $actId]);
            } catch (AppException $e) {
                return $this->renderPage($profileId, $actId, $e->getMessage(), array_values((array) ($input['commission'] ?? [])));
            }
        }

        return $this->renderPage($profileId, $actId, null);
    }

    /** @param list<array<string, mixed>>|null $postedCommission Строки комиссии из POST — вернуть в форму при ошибке оформления. */
    private function renderPage(string $profileId, string $actId, ?string $error, ?array $postedCommission = null): Response
    {
        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $profileResult->profile) {
            throw $this->createNotFoundException('Профиль не найден.');
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

        // Периодичность по позициям — из проекции (тот же источник, что у акта выдачи), для единой меты.
        $cadenceByKey = [];
        /** @var GetProfileComplianceQueryResult $complianceResult */
        $complianceResult = $this->queryBus->execute(new GetProfileComplianceQuery($profileId));
        foreach (null !== $complianceResult->compliance ? $complianceResult->compliance->obligations : [] as $o) {
            $cadenceByKey[$o->key] = $o->cadenceLabel;
        }

        // Уже выбранное в акте: recordId → {количество, причина}.
        $chosen = [];
        foreach ($profileCompliance->itemsOfWriteOffAct($actId) as $portion) {
            $chosen[$portion->recordId()] = ['quantity' => $portion->quantity(), 'reason' => $portion->reason()?->value];
        }

        if ($act->isDraft()) {
            // Позиции как в акте выдачи: действующие материальные факты требования + поля «Списать N» и причина.
            $positions = [];
            foreach ($profileCompliance->recordsForRequirement($act->requirementId()) as $record) {
                if (null === $record->quantity() || $record->heldAmount() <= 0.0) {
                    continue;
                }
                $pick = $chosen[$record->getId()] ?? null;
                $cadence = $cadenceByKey[$record->obligationKey()] ?? '';
                $heldStr = AmountFormatter::trimmed($record->heldAmount());
                $unit = $record->quantity()->unit->title();
                $positions[] = [
                    'recordId' => $record->getId(),
                    'label' => $profileCompliance->obligationLabelOf($record->obligationKey()),
                    'meta' => trim(($cadence ? $cadence.' · ' : '').'на руках '.$heldStr.' '.$unit.' · выдано '.$record->fulfilledAt()->format('d.m.Y')),
                    'held' => $heldStr,
                    'unit' => $unit,
                    'quantity' => null !== $pick ? AmountFormatter::trimmed($pick['quantity']) : '',
                    'reason' => $pick['reason'] ?? null,
                ];
            }

            return $this->render('admin/compliance/writeoff/act.html.twig', [
                'profileId' => $profileId,
                'actId' => $actId,
                'requirementId' => $act->requirementId(),
                'profile' => $profileResult->profile,
                'draft' => true,
                'positions' => $positions,
                'actNumber' => $act->actNumber(),
                'actDate' => $act->actDate()?->format('Y-m-d') ?: date('Y-m-d'),
                'reasons' => array_map(static fn (WriteOffReason $r): array => ['value' => $r->value, 'title' => $r->title()], WriteOffReason::cases()),
                'commissionRows' => $postedCommission ?? [],
                'error' => $error,
            ]);
        }

        // Оформленный акт — только просмотр: что списано + комиссия + скан.
        $signedItems = [];
        foreach ($profileCompliance->itemsOfWriteOffAct($actId) as $portion) {
            $fact = $profileCompliance->recordById($portion->recordId());
            $cadence = null !== $fact ? ($cadenceByKey[$fact->obligationKey()] ?? '') : '';
            $signedItems[] = [
                'label' => null !== $fact ? $profileCompliance->obligationLabelOf($fact->obligationKey()) : '',
                'meta' => trim(($cadence ? $cadence.' · ' : '').'выдано '.($fact?->fulfilledAt()->format('d.m.Y') ?? '')),
                'quantityLabel' => AmountFormatter::trimmed($portion->quantity()).' '.($fact?->quantity()?->unit->title() ?? ''),
                'reason' => $portion->reason()?->title(),
            ];
        }
        $commission = $act->commission();
        $commissionMembers = null !== $commission ? array_map(
            static fn (CommissionMember $m): array => [
                'organization' => $m->organization,
                'position' => $m->position,
                'fio' => $m->fio,
                'date' => $m->date,
            ],
            $commission->members,
        ) : [];

        return $this->render('admin/compliance/writeoff/act.html.twig', [
            'profileId' => $profileId,
            'actId' => $actId,
            'requirementId' => $act->requirementId(),
            'profile' => $profileResult->profile,
            'draft' => false,
            'signedItems' => $signedItems,
            'actNumber' => $act->actNumber(),
            'actDate' => $act->actDate()?->format('d.m.Y'),
            'commissionMembers' => $commissionMembers,
            'error' => $error,
        ]);
    }
}
