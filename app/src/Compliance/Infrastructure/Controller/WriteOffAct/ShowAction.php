<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\Service\AmountFormatter;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffActLine;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\ObligationKey;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\ValueObject\CommissionMember;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Страница акта списания (самостоятельный кросс-персон агрегат {@see WriteOffAct} — резолвим по его id, без
 * profileId в адресе). Позиции — строки акта, сгруппированные по людям: у каждой наименование + на руках у
 * владельца, количество к списанию и причина. POST: «Сохранить» (op=save — состав+реквизиты) или «Оформить»
 * (op=sign — + комиссия/скан → заморозка и эффект: гашение количества у всех затронутых людей). Права — в хендлерах.
 */
#[Route(
    path: '/cabinet/compliance/writeoff/{actId}',
    name: 'app_cabinet_compliance_writeoff_show',
    methods: ['GET', 'POST'],
)]
final class ShowAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly WriteOffActRepositoryInterface $writeOffActRepository,
        private readonly ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(Request $request, string $actId): Response
    {
        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $input */
            $input = $request->getPayload()->all();
            /** @var list<array{recordId: string, quantity: float, reason: string}> $lines */
            $lines = array_values((array) ($input['portions'] ?? []));
            $commissionRows = array_values((array) ($input['commission'] ?? []));
            $actDate = (string) ($input['actDate'] ?? '');
            $orderNumber = (string) ($input['orderNumber'] ?? '');
            $orderDate = (string) ($input['orderDate'] ?? '');
            $representativePosition = (string) ($input['representativePosition'] ?? '');
            $representativeFio = (string) ($input['representativeFio'] ?? '');
            try {
                // И «Сохранить», и «Оформить» сперва пишут состав + реквизиты (дата/приказ/представитель/комиссия) на
                // черновик. Номер акта тут не задаётся — он сквозной, присваивается при оформлении.
                $this->commandBus->execute(new SaveWriteOffActCommand(
                    $actId, $lines, $actDate, $commissionRows,
                    $orderNumber, $orderDate, $representativePosition, $representativeFio,
                ));
                if ('save' === ($input['op'] ?? 'sign')) {
                    $this->addFlash('success', 'Черновик акта списания сохранён.');

                    return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['actId' => $actId]);
                }
                $this->commandBus->execute(new SignWriteOffActCommand(
                    $actId,
                    $commissionRows,
                    $actDate,
                    ((string) ($input['stagedFileId'] ?? '')) ?: null,
                ));
                $this->addFlash('success', 'Акт списания оформлен. Позиции списаны.');

                return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['actId' => $actId]);
            } catch (OptimisticLockException) {
                // Акт правили параллельно (двойной клик / другое окно): версия агрегата разошлась. Данные целы
                // (дублей/второго черновика нет) — просим обновить и повторить вместо 500.
                return $this->renderPage($actId, 'Акт списания только что изменился в другом окне. Обновите страницу и повторите.', $commissionRows);
            } catch (AppException $e) {
                return $this->renderPage($actId, $e->getMessage(), $commissionRows);
            }
        }

        return $this->renderPage($actId, null);
    }

    /** @param list<array<string, mixed>>|null $postedCommission Строки комиссии из POST — вернуть в форму при ошибке оформления. */
    private function renderPage(string $actId, ?string $error, ?array $postedCommission = null): Response
    {
        $act = $this->writeOffActRepository->findById($actId)
            ?? throw $this->createNotFoundException('Акт списания не найден.');

        // Акт кросс-персон: грузим профиль и учёт каждого затронутого человека один раз (наименование/единица/
        // на руках/дата берутся с его факта-выдачи).
        $profiles = [];
        $owners = [];
        foreach ($act->profileIds() as $profileId) {
            /** @var GetProfileQueryResult $profileResult */
            $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
            if (null !== $profileResult->profile) {
                $profiles[$profileId] = $profileResult->profile;
            }
            $owner = $this->repository->findByProfile($profileId);
            if (null !== $owner) {
                $owners[$profileId] = $owner;
            }
        }

        $backProfileId = $act->profileIds()[0] ?? null;

        if ($act->isDraft()) {
            // Позиции сгруппированы по людям (секция на человека), сквозной индекс строки держит portions[] плоским.
            $personGroups = [];
            $index = 0;
            foreach ($act->linesByProfile() as $profileId => $lines) {
                $positions = [];
                foreach ($lines as $line) {
                    $position = $this->draftPosition($line, $owners);
                    $position['index'] = $index++;
                    $positions[] = $position;
                }
                $personGroups[] = $this->personGroup($profileId, $profiles, $positions);
            }

            // Комиссия на reload — из сохранённого черновика (при ошибке — из POST, чтобы не терять ввод).
            $savedCommission = $act->commission();
            $commissionRows = $postedCommission ?? (null !== $savedCommission ? array_map(
                static fn (CommissionMember $m): array => ['organization' => $m->organization, 'position' => $m->position, 'fio' => $m->fio, 'date' => $m->date],
                $savedCommission->members,
            ) : []);

            return $this->render('admin/compliance/writeoff/act.html.twig', [
                'actId' => $actId,
                'requirementId' => $act->requirementId(),
                'backProfileId' => $backProfileId,
                'draft' => true,
                'personGroups' => $personGroups,
                'hasPositions' => $index > 0,
                'actNumber' => $act->actNumber(),
                'actDate' => $act->actDate()?->format('Y-m-d') ?: date('Y-m-d'),
                'orderNumber' => $act->orderNumber(),
                'orderDate' => $act->orderDate()?->format('Y-m-d'),
                'representativePosition' => $act->representativePosition(),
                'representativeFio' => $act->representativeFio(),
                'reasons' => array_map(static fn (WriteOffReason $r): array => ['value' => $r->value, 'title' => $r->title()], WriteOffReason::cases()),
                'commissionRows' => $commissionRows,
                'error' => $error,
            ]);
        }

        // Оформленный акт — только просмотр: что списано + комиссия + скан, тоже группами по людям.
        $signedGroups = [];
        foreach ($act->linesByProfile() as $profileId => $lines) {
            $items = [];
            foreach ($lines as $line) {
                $items[] = $this->signedItem($line, $owners);
            }
            $signedGroups[] = $this->personGroup($profileId, $profiles, $items);
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
            'actId' => $actId,
            'requirementId' => $act->requirementId(),
            'backProfileId' => $backProfileId,
            'draft' => false,
            'signedGroups' => $signedGroups,
            'actNumber' => $act->actNumber(),
            'actDate' => $act->actDate()?->format('d.m.Y'),
            'commissionMembers' => $commissionMembers,
            'error' => $error,
        ]);
    }

    /**
     * Шапка секции-человека: ФИО + должность + число позиций. Строки позиций уже посчитаны вызывающим.
     *
     * @param array<string, ProfileDTO>  $profiles
     * @param list<array<string, mixed>> $positions
     *
     * @return array<string, mixed>
     */
    private function personGroup(string $profileId, array $profiles, array $positions): array
    {
        $profile = $profiles[$profileId] ?? null;

        return [
            'fio' => $profile?->fio() ?? '',
            'position' => null !== $profile ? $profile->positionTitle : '',
            'count' => \count($positions),
            'positions' => $positions,
        ];
    }

    /**
     * Строка позиции черновика. ФИО владельца — в шапке секции, поэтому в meta его нет: только «на руках» и дата.
     *
     * @param array<string, ProfileCompliance> $owners
     *
     * @return array<string, mixed>
     */
    private function draftPosition(WriteOffActLine $line, array $owners): array
    {
        $fact = ($owners[$line->profileId()] ?? null)?->recordById($line->recordId());
        $held = $fact?->heldAmount() ?? $line->quantity();
        $unit = $fact?->quantity()?->unit->title() ?? '';
        $heldStr = AmountFormatter::trimmed($held);
        $issued = null !== $fact ? ' · выдано '.$fact->fulfilledAt()->format('d.m.Y') : '';

        return [
            'recordId' => $line->recordId(),
            'label' => ObligationKey::humanLabelOf($line->labelSnapshot()),
            'meta' => trim('на руках '.$heldStr.' '.$unit.$issued),
            'held' => $heldStr,
            'unit' => $unit,
            'quantity' => AmountFormatter::trimmed($line->quantity()),
            'reason' => $line->reason()?->value,
        ];
    }

    /**
     * Строка списанной позиции (просмотр). ФИО владельца — в шапке секции, в meta только дата выдачи.
     *
     * @param array<string, ProfileCompliance> $owners
     *
     * @return array<string, mixed>
     */
    private function signedItem(WriteOffActLine $line, array $owners): array
    {
        $fact = ($owners[$line->profileId()] ?? null)?->recordById($line->recordId());
        $unit = $fact?->quantity()?->unit->title() ?? '';

        return [
            'label' => ObligationKey::humanLabelOf($line->labelSnapshot()),
            'meta' => null !== $fact ? 'выдано '.$fact->fulfilledAt()->format('d.m.Y') : '',
            'quantityLabel' => AmountFormatter::trimmed($line->quantity()).('' !== $unit ? ' '.$unit : ''),
            'reason' => $line->reason()?->title(),
        ];
    }
}
