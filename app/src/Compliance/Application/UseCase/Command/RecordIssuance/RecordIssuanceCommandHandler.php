<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecordIssuance;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\ComplianceObligationProjector;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Infrastructure\Mapper\IssuanceLineMapper;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Прямая дозапись выдачи в журнал. Строки формы (+ позиции вне нормы) собираем в {@see IssuanceLine} и отдаём
 * домену ОДНИМ вызовом: требование с видом журнала → {@see ProfileCompliance::recordInstruction} (инвариант
 * полноты полей инструктажа), иначе → {@see ProfileCompliance::recordIssuance} (инвариант «не меньше нормы»).
 * Инварианты живут в домене — хендлер только оркеструет (достать учёт, собрать строки, записать). Черновика,
 * топ-апа, подписи и скана здесь нет: форма шлёт одну строку на позицию, запись неизменяема сразу.
 * № карточки СИЗ — передаём домену как есть; закреплять его один раз или игнорировать как readonly решает
 * {@see ProfileCompliance::assignCardNumber()}.
 */
final readonly class RecordIssuanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private ObligationDueCalculator $calculator,
        private IssuanceLineMapper $mapper,
        private RequirementRepositoryInterface $requirements,
        private ComplianceObligationProjector $projector,
    ) {
    }

    public function __invoke(RecordIssuanceCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $profileCompliance->assignCardNumber($command->cardNumber ?? '');

        $documentDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->documentDate))
            ?: throw new AppException('Укажите дату документа.');

        $lines = $this->mapper->fromInput($command->items, $documentDate);
        $lines = [...$lines, ...$this->materializePersonalItems($profileCompliance, $command, $documentDate)];

        $journalKind = $this->requirements->findOneById($command->requirementId)?->getJournalKind();
        if (null !== $journalKind) {
            $profileCompliance->recordInstruction($this->calculator, $journalKind, ...$lines);
        } else {
            $profileCompliance->recordIssuance($this->calculator, ...$lines);
        }
        $this->repository->add($profileCompliance);

        // Write-through: синхронно материализуем строки новой read-модели этого требования в ТОЙ ЖЕ транзакции
        // команды (шина — doctrine_transaction). Агрегат при этом ПРОДОЛЖАЕТ вести старую таблицу через
        // recomputeObligation — намеренный параллельный прогон (expand/contract), запись в РАЗНЫЕ таблицы.
        $this->projector->projectProfile($command->profileId, $command->requirementId);
    }

    /**
     * Позиции вне нормы, добавленные прямо в форму → материализуем в персональные обязанности (origin=Personal,
     * cadence «по дате»). Кандидат id/itemId генерим здесь (как с черновиком списания) и отдаём домену — тот
     * дедупит по наименованию (повтор того же label = та же позиция) и возвращает её ключ. Возвращает строки
     * выдачи этих позиций.
     *
     * @return list<IssuanceLine>
     */
    private function materializePersonalItems(ProfileCompliance $profileCompliance, RecordIssuanceCommand $command, \DateTimeImmutable $documentDate): array
    {
        if ([] === $command->personalItems) {
            return [];
        }
        $lines = [];
        foreach ($command->personalItems as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ('' === $label) {
                continue;
            }
            $quantity = $this->mapper->quantityOf($row); // null, если поля кол-ва нет (нематериальный акт)
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
            $key = $profileCompliance->addPersonalObligation(
                Uuid::v7(), Uuid::v7()->toRfc4122(), $command->requirementId, $label, new Cadence(CadenceKind::ByManufacturerDoc), $quantity,
            );
            $lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due, $this->mapper->note($row), $this->mapper->instructionDetails($row));
        }

        return $lines;
    }
}
