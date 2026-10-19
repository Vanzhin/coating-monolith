<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecordIssuance;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\ComplianceObligationProjector;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
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
 * Норму резолвит домен из живого `Requirement` (Вариант B — агрегат её не копирует), хендлер его грузит и
 * прокидывает. Инварианты живут в домене — хендлер только оркеструет (достать учёт+требование, собрать строки,
 * записать). Черновика, топ-апа, подписи и скана здесь нет: форма шлёт одну строку на позицию, запись
 * неизменяема сразу. № карточки СИЗ — передаём домену как есть; закреплять его один раз или игнорировать как
 * readonly решает {@see ProfileCompliance::assignCardNumber()}.
 */
final readonly class RecordIssuanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
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
        $requirement = $this->requirements->findOneById($command->requirementId)
            ?? throw new AppException('Требование не найдено.');

        $profileCompliance->assignCardNumber($command->cardNumber ?? '');

        $documentDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->documentDate))
            ?: throw new AppException('Укажите дату документа.');

        $lines = $this->mapper->fromInput($command->items, $documentDate);
        $lines = [...$lines, ...$this->materializePersonalItems($profileCompliance, $requirement, $command, $documentDate)];

        if (null !== $requirement->getJournalKind()) {
            $profileCompliance->recordInstruction($requirement, ...$lines);
        } else {
            $profileCompliance->recordIssuance($requirement, ...$lines);
        }
        $this->repository->add($profileCompliance);

        // Write-through: синхронно материализуем строки read-модели этого требования в ТОЙ ЖЕ транзакции
        // команды (шина — doctrine_transaction).
        $this->projector->projectProfile($command->profileId, $command->requirementId);
    }

    /**
     * Позиции вне нормы, добавленные прямо в форму → материализуем в персональные декларации (вне нормы,
     * cadence «по дате»). itemId генерим здесь (как с черновиком списания) и отдаём домену — тот дедупит по
     * наименованию (повтор того же label = та же позиция) и возвращает её ключ. Тип позиции — из строки формы
     * (на смешанной «Выдаче» позиция вне нормы может оказаться расходником, не только инвентарём, как и
     * норменная — {@see \App\Compliance\Application\Service\RequirementItemBuilder}); не прислано (старая форма,
     * «Периодическая» без выбора) — дефолт тип требования. Возвращает строки выдачи этих позиций.
     *
     * @return list<IssuanceLine>
     */
    private function materializePersonalItems(ProfileCompliance $profileCompliance, Requirement $requirement, RecordIssuanceCommand $command, \DateTimeImmutable $documentDate): array
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
            $itemType = ComplianceType::tryFrom((string) ($row['type'] ?? '')) ?? $requirement->getType();
            $quantity = $this->mapper->quantityOf($row); // null, если поля кол-ва нет (нематериальный акт)
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
            $key = $profileCompliance->addPersonalObligation(
                Uuid::v7()->toRfc4122(), $requirement->getId(), $label, $itemType, new Cadence(CadenceKind::ByManufacturerDoc), $quantity,
            );
            $lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due, $this->mapper->note($row), $this->mapper->instructionDetails($row));
        }

        return $lines;
    }
}
