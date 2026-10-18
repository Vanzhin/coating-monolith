<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Console;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\DocumentStatus;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\ValueObject\Commission;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Разовый идемпотентный перенос старых per-person актов списания (`compliance_write_off_act`/`_item`) в новый
 * кросс-персон агрегат {@see WriteOffAct} (`compliance_writeoff_act`/`_line`). Не SQL-миграция, а команда:
 * организация и наименование позиции не плоские колонки — организацию резолвит {@see GetProfileQuery} по
 * профилю-владельцу, наименование — домен ({@see ProfileCompliance::obligationLabelOf}). Гонится ПОСЛЕ
 * `doctrine:migrations:migrate` (как rekey гонит rebuild-projection).
 *
 * Правила переноса:
 * - Подписанный старый акт → новый 1:1, id переиспользуется → повторный прогон видит его по id и пропускает.
 * - Черновики одной пары (требование × организация) сливаются в ОДИН новый акт; целевой id — наименьший id
 *   среди группы (детерминирован → повторный прогон стабилен).
 * - Эффект подписи (гашение «на руках» на фактах выдачи) НЕ применяется: на старом пути held уже погашен,
 *   переносим лишь ФАКТ существования подписанного акта. {@see WriteOffAct::sign} только замораживает документ
 *   и не трогает {@see ProfileCompliance}; {@see WriteOffActRepositoryInterface::add} только persist+flush,
 *   событий не шлёт — поэтому реконструкция подписанного акта безопасна.
 */
#[AsCommand(
    name: 'app:compliance:migrate-writeoff-crossperson',
    description: 'Перенести старые per-person акты списания в новый кросс-персон агрегат WriteOffAct.',
)]
final class MigrateWriteOffCrossPersonCommand extends Command
{
    /** @var array<string, ?ProfileCompliance> кэш учёта по profileId (read-only на время прогона) */
    private array $profileComplianceCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryBusInterface $queryBus,
        private readonly ProfileComplianceRepositoryInterface $profileComplianceRepository,
        private readonly WriteOffActRepositoryInterface $writeOffActRepository,
        private readonly RequirementRepositoryInterface $requirements,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->profileComplianceCache = [];

        $oldActs = $this->loadOldActs($output);
        $targets = $this->groupIntoTargets($oldActs);

        $migratedActs = 0;
        $migratedLines = 0;
        $skipped = 0;
        $droppedLines = 0;

        foreach ($targets as $targetId => $group) {
            if (null !== $this->writeOffActRepository->findById($targetId)) {
                ++$skipped; // уже перенесён (тот же id) — идемпотентный повтор
                continue;
            }
            try {
                ['lines' => $lines, 'dropped' => $dropped] = $this->migrateGroup($targetId, $group);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Акт %s не перенесён: %s</error>', $targetId, $e->getMessage()));
                continue;
            }
            ++$migratedActs;
            $migratedLines += $lines;
            $droppedLines += $dropped;
        }

        $output->writeln(sprintf('Перенесено актов: %d, строк: %d.', $migratedActs, $migratedLines));
        $output->writeln(sprintf('Пропущено (уже перенесены): %d.', $skipped));
        if ($droppedLines > 0) {
            $output->writeln(sprintf('<comment>Пропущено строк (факт выдачи не найден): %d.</comment>', $droppedLines));
        }

        return Command::SUCCESS;
    }

    /**
     * Старые акты с присоединёнными строками, профилем-владельцем и его организацией. Акт, чей профиль/учёт/
     * организация не резолвятся, пропускаем с предупреждением — лучше явная строка в выводе, чем тихая потеря.
     *
     * @return list<array{
     *     id: string, requirementId: string, status: string, profileId: string, organizationId: string,
     *     commission: ?string, actNumber: ?string, actDate: ?string, orderNumber: ?string, orderDate: ?string,
     *     representativePosition: ?string, representativeFio: ?string, scanFileId: ?string, signedAt: ?string,
     *     createdAt: string, updatedAt: string, items: list<array{recordId: string, quantity: float, reason: ?string}>
     * }>
     */
    private function loadOldActs(OutputInterface $output): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM compliance_write_off_act ORDER BY id');

        $acts = [];
        foreach ($rows as $row) {
            $actId = (string) $row['id'];
            $profileId = $this->connection->fetchOne(
                'SELECT profile_id FROM compliance_profile_compliance WHERE id = ?',
                [(string) $row['profile_compliance_id']],
            );
            if (false === $profileId) {
                $output->writeln(sprintf('<comment>Акт %s: учёт-владелец не найден — пропуск.</comment>', $actId));
                continue;
            }
            $profileId = (string) $profileId;

            $organizationId = $this->resolveOrganizationId($profileId);
            if (null === $organizationId) {
                $output->writeln(sprintf('<comment>Акт %s: организация профиля %s не резолвится — пропуск.</comment>', $actId, $profileId));
                continue;
            }

            $acts[] = [
                'id' => $actId,
                'requirementId' => (string) $row['requirement_id'],
                'status' => (string) $row['status'],
                'profileId' => $profileId,
                'organizationId' => $organizationId,
                'commission' => null !== $row['commission'] ? (string) $row['commission'] : null,
                'actNumber' => null !== $row['act_number'] ? (string) $row['act_number'] : null,
                'actDate' => null !== $row['act_date'] ? (string) $row['act_date'] : null,
                'orderNumber' => null !== ($row['order_number'] ?? null) ? (string) $row['order_number'] : null,
                'orderDate' => null !== ($row['order_date'] ?? null) ? (string) $row['order_date'] : null,
                'representativePosition' => null !== ($row['representative_position'] ?? null) ? (string) $row['representative_position'] : null,
                'representativeFio' => null !== ($row['representative_fio'] ?? null) ? (string) $row['representative_fio'] : null,
                'scanFileId' => null !== $row['scan_file_id'] ? (string) $row['scan_file_id'] : null,
                'signedAt' => null !== $row['signed_at'] ? (string) $row['signed_at'] : null,
                'createdAt' => (string) $row['created_at'],
                'updatedAt' => (string) $row['updated_at'],
                'items' => $this->loadItems($actId),
            ];
        }

        return $acts;
    }

    /** @return list<array{recordId: string, quantity: float, reason: ?string}> */
    private function loadItems(string $oldActId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT record_id, quantity, reason FROM compliance_write_off_item WHERE write_off_act_id = ?',
            [$oldActId],
        );

        return array_map(static fn (array $r): array => [
            'recordId' => (string) $r['record_id'],
            'quantity' => (float) $r['quantity'],
            'reason' => null !== $r['reason'] ? (string) $r['reason'] : null,
        ], $rows);
    }

    private function resolveOrganizationId(string $profileId): ?string
    {
        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));

        return $result->profile?->organizationId;
    }

    /**
     * Цель переноса на старый акт: подписанный — сам по себе (id = свой), черновики одной (требование×организация)
     * — одна группа с детерминированным id (наименьший id в группе). Внутри группы акты упорядочены по id.
     *
     * @param list<array{id: string, requirementId: string, status: string, organizationId: string, items: list<array{recordId: string, quantity: float, reason: ?string}>}&array<string, mixed>> $oldActs
     *
     * @return array<string, list<array<string, mixed>>> targetId → старые акты группы (отсортированы по id)
     */
    private function groupIntoTargets(array $oldActs): array
    {
        /** @var array<string, list<array<string, mixed>>> $draftGroups */
        $draftGroups = [];
        /** @var array<string, list<array<string, mixed>>> $targets */
        $targets = [];

        foreach ($oldActs as $act) {
            if (DocumentStatus::Signed->value === $act['status']) {
                $targets[$act['id']] = [$act]; // подписанный 1:1, id переиспользуется
                continue;
            }
            $groupKey = $act['requirementId'].'|'.$act['organizationId'];
            $draftGroups[$groupKey][] = $act;
        }

        foreach ($draftGroups as $group) {
            usort($group, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
            $targetId = (string) $group[0]['id']; // наименьший id группы — детерминированная цель
            $targets[$targetId] = $group;
        }

        return $targets;
    }

    /**
     * Собрать и сохранить новый акт из группы старых (через публичный API агрегата — ORM/инварианты срабатывают).
     *
     * @param list<array<string, mixed>> $group старые акты одной цели (упорядочены по id; [0] — primary для шапки)
     *
     * @return array{lines: int, dropped: int}
     */
    private function migrateGroup(string $targetId, array $group): array
    {
        /** @var array{id: string, requirementId: string, status: string, profileId: string, organizationId: string, commission: ?string, actNumber: ?string, actDate: ?string, orderNumber: ?string, orderDate: ?string, representativePosition: ?string, representativeFio: ?string, scanFileId: ?string, signedAt: ?string, createdAt: string, updatedAt: string, items: list<array{recordId: string, quantity: float, reason: ?string}>} $primary */
        $primary = $group[0];

        $createdAt = new \DateTimeImmutable($primary['createdAt']);
        $act = new WriteOffAct(Uuid::fromString($targetId), $primary['requirementId'], $primary['organizationId'], $createdAt);
        $requirement = $this->requirements->findOneById($primary['requirementId']); // для человекочитаемого label строки

        /** @var array<string, list<array{recordId: string, labelSnapshot: string, quantity: float}>> $linesByProfile */
        $linesByProfile = [];
        /** @var array<string, ?WriteOffReason> $reasonByRecordId */
        $reasonByRecordId = [];
        $dropped = 0;

        foreach ($group as $old) {
            $profileId = (string) $old['profileId'];
            $profileCompliance = $this->profileCompliance($profileId);
            /** @var list<array{recordId: string, quantity: float, reason: ?string}> $items */
            $items = $old['items'];
            foreach ($items as $item) {
                if (null === $profileCompliance || null === ($fact = $profileCompliance->recordById($item['recordId']))) {
                    ++$dropped; // учёт/факт выдачи не резолвится — переносить нечего, считаем явно (не тихо)
                    continue;
                }
                $linesByProfile[$profileId][] = [
                    'recordId' => $item['recordId'],
                    'labelSnapshot' => null !== $requirement
                        ? $profileCompliance->obligationLabelOf($requirement, $fact->obligationKey())
                        : $fact->obligationKey(),
                    'quantity' => $item['quantity'],
                ];
                $reasonByRecordId[$item['recordId']] = null !== $item['reason'] ? WriteOffReason::tryFrom($item['reason']) : null;
            }
        }

        foreach ($linesByProfile as $profileId => $lines) {
            $act->addLinesForProfile($profileId, $lines);
        }

        $portions = [];
        foreach ($act->lines() as $line) {
            $portions[] = [
                'recordId' => $line->recordId(),
                'quantity' => $line->quantity(),
                'reason' => $reasonByRecordId[$line->recordId()] ?? null,
            ];
        }
        if ([] !== $portions) {
            $act->replacePortions($portions); // единственный публичный путь проставить причины строкам
        }

        $commission = $this->commissionOf($primary['commission']);
        $actDate = null !== $primary['actDate'] ? new \DateTimeImmutable($primary['actDate']) : null;
        $act->saveDetails(
            $primary['actNumber'] ?? '',
            $actDate,
            $commission,
            $primary['orderNumber'] ?? '',
            null !== $primary['orderDate'] ? new \DateTimeImmutable($primary['orderDate']) : null,
            $primary['representativePosition'] ?? '',
            $primary['representativeFio'] ?? '',
            new \DateTimeImmutable($primary['updatedAt']),
        );

        if (DocumentStatus::Signed->value === $primary['status']) {
            $signedAt = new \DateTimeImmutable($primary['signedAt'] ?? $primary['updatedAt']);
            $act->sign(
                $commission,
                $primary['actNumber'] ?? '',
                $actDate ?? $signedAt,
                $primary['scanFileId'] ?? '',
                $signedAt, // переносим момент подписи, а не время прогона команды
            );
        }

        $this->writeOffActRepository->add($act);

        return ['lines' => \count($act->lines()), 'dropped' => $dropped];
    }

    private function commissionOf(?string $json): Commission
    {
        if (null === $json || '' === trim($json)) {
            return new Commission();
        }
        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode($json, true);

        return Commission::fromArray($decoded);
    }

    private function profileCompliance(string $profileId): ?ProfileCompliance
    {
        return $this->profileComplianceCache[$profileId] ??= $this->profileComplianceRepository->findByProfile($profileId);
    }
}
