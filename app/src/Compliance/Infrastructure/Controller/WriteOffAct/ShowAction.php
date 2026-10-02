<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Application\UseCase\Command\SetWriteOffReasons\SetWriteOffReasonsCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\WriteOffCommissionMember;
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
 * Страница акта списания (зеркало акта получения). GET — просмотр/редактирование; POST: «Сохранить» (op=save,
 * причины позиций) или «Оформить» (op отсутствует — причины + комиссия + №/дата + скан → заморозка и эффект).
 * Откат позиции — отдельным экшеном. Права — в хендлерах.
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
            /** @var array<string, string> $reasons */
            $reasons = (array) ($input['reasons'] ?? []);
            try {
                $this->commandBus->execute(new SetWriteOffReasonsCommand($profileId, $actId, $reasons));
                if ('save' === ($input['op'] ?? 'sign')) {
                    $this->addFlash('success', 'Причины сохранены.');

                    return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['profileId' => $profileId, 'actId' => $actId]);
                }
                $this->commandBus->execute(new SignWriteOffActCommand(
                    $profileId,
                    $actId,
                    (string) ($input['representativeProfileId'] ?? ''),
                    array_values(array_filter((array) ($input['memberProfileIds'] ?? []), static fn ($v): bool => '' !== trim((string) $v))),
                    (string) ($input['actNumber'] ?? ''),
                    (string) ($input['actDate'] ?? ''),
                    ((string) ($input['stagedFileId'] ?? '')) ?: null,
                ));
                $this->addFlash('success', 'Акт списания оформлен. Позиции списаны.');

                return $this->redirectToRoute('app_cabinet_compliance_writeoff_show', ['profileId' => $profileId, 'actId' => $actId]);
            } catch (AppException $e) {
                return $this->renderPage($profileId, $actId, $e->getMessage());
            }
        }

        return $this->renderPage($profileId, $actId, null);
    }

    private function renderPage(string $profileId, string $actId, ?string $error): Response
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

        $items = [];
        foreach ($profileCompliance->itemsOfWriteOffAct($actId) as $portion) {
            $fact = $profileCompliance->recordById($portion->recordId());
            $items[] = [
                'portionId' => $portion->getId(),
                'label' => null !== $fact ? $profileCompliance->obligationLabelOf($fact->obligationKey()) : '',
                'quantityLabel' => $this->formatQuantity($portion->quantity(), $fact?->quantity()?->unit->title() ?? ''),
                'issueDate' => $fact?->fulfilledAt()->format('Y-m-d') ?? '',
                'reason' => $portion->reason()?->value,
            ];
        }

        $commission = $act->commission();
        $commissionView = null;
        if (null !== $commission) {
            $commissionView = [
                'representative' => trim($commission->representative->position.' — '.$commission->representative->fio),
                'members' => array_map(
                    static fn (WriteOffCommissionMember $m): string => trim($m->position.' — '.$m->fio),
                    $commission->members,
                ),
            ];
        }

        return $this->render('admin/compliance/writeoff/act.html.twig', [
            'profileId' => $profileId,
            'actId' => $actId,
            'requirementId' => $act->requirementId(),
            'profile' => $profileResult->profile,
            'organizationId' => $profileResult->profile->organizationId,
            'draft' => $act->isDraft(),
            'actNumber' => $act->actNumber(),
            'actDate' => $act->actDate()?->format('Y-m-d') ?: date('Y-m-d'),
            'items' => $items,
            'reasons' => array_map(static fn (WriteOffReason $r): array => ['value' => $r->value, 'title' => $r->title()], WriteOffReason::cases()),
            'commission' => $commissionView,
            'hasScan' => null !== $act->scanFileId(),
            'error' => $error,
        ]);
    }

    /** Количество порции + единица — без хвостового «.0» (как {@see \App\Compliance\Domain\ValueObject\Quantity::label()}, но для «голого» float порции). */
    private function formatQuantity(float $amount, string $unit): string
    {
        $value = 0.0 === fmod($amount, 1.0) ? (string) (int) $amount : (string) $amount;

        return '' === $unit ? $value : $value.' '.$unit;
    }
}
