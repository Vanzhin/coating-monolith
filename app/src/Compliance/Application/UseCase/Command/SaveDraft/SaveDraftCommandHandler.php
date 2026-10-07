<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\File\RequirementScanPurpose;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Infrastructure\Mapper\IssuanceLineMapper;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Сохранение/оформление черновика единой командой. Строки формы (+ персональные позиции) собираем всегда.
 * Кнопка «Сохранить» (sign=false) и нет скана → `saveDraft` (корзина + реквизиты, без подписи, held не трогаем).
 * Иначе оформление: `assertIssuable` + `signDraft` (инвариант + факты + подпись + трекинг) и ТОЛЬКО если домен
 * принял — промоут скана в хранилище. Порядок подпись→промоут важен: отказ домена не двигает staged-файл, повтор
 * оформления работает (id StoredFile при промоуте не меняется, поэтому подпись пишет его заранее).
 */
final readonly class SaveDraftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private ObligationDueCalculator $calculator,
        private FileStorage $storage,
        private IssuanceLineMapper $mapper,
        private RequirementRepositoryInterface $requirements,
    ) {
    }

    public function __invoke(SaveDraftCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $documentDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->documentDate))
            ?: throw new AppException('Укажите дату документа.');
        $lines = $this->mapper->fromInput($command->items, $documentDate);
        $lines = [...$lines, ...$this->materializePersonalItems($profileCompliance, $command, $documentDate)];

        $now = new \DateTimeImmutable();
        $staged = trim((string) $command->stagedFileId);

        if (!$command->sign && '' === $staged) {
            // «Сохранить черновик»: без скана, без инварианта кол-ва — корзину можно сохранить недозаполненной.
            $profileCompliance->saveDraft($command->documentId, $command->actNumber, $command->responsibleFio, $lines, $now);
            $this->repository->add($profileCompliance);

            return;
        }

        // «Оформить»: подпись. Решает КНОПКА (sign) ИЛИ приложенный скан — иначе «Оформить» без скана молча
        // сохранял бы черновик вместо ошибки. Инвариант «нужен скан» проверяет домен ({@see signDraft}):
        // скан не приложен → AppException, контроллер перерисует форму с ошибкой.
        //
        // ПОРЯДОК: подпись ДО промоута. id StoredFile при промоуте не меняется, поэтому подпись пишет его заранее,
        // а файл переносим в хранилище ТОЛЬКО когда домен принял (assertIssuable + signDraft не кинули). Иначе
        // отказ домена двигал бы файл, а откат транзакции вернул бы строку StoredFile на tmp-ключ → staged-скан
        // терялся и повтор оформления падал на пропавшем источнике.
        $profileCompliance->assertIssuable($lines);
        $this->assertInstructionComplete($profileCompliance, $command->documentId, $lines);
        $fileId = '' === $staged ? null : $staged; // domain требует непустой скан; реальный перенос — ниже
        $profileCompliance->signDraft($command->documentId, $fileId, $lines, $this->calculator, $now, $command->actNumber, $command->responsibleFio);
        if (null !== $fileId) {
            try {
                $this->storage->promote($staged, RequirementScanPurpose::SignedCard, $profileCompliance->getId());
            } catch (AppException $e) {
                throw $e; // валидация скана (mime/размер/габариты) — сообщение уже человекочитаемое
            } catch (\Throwable $e) {
                // напр. staged-файл истёк/потерян — не сырой 500, а понятная просьба приложить заново
                throw new AppException('Не удалось приложить скан — загрузите файл заново.', log: ['error' => $e->getMessage()], previous: $e);
            }
        }
        $this->repository->add($profileCompliance);
    }

    /**
     * Позиции вне нормы, добавленные прямо в акт → материализуем в персональные обязанности (origin=Personal,
     * cadence «по дате»). Идемпотентно: если обязанность с таким ключом уже есть (повторное сохранение) —
     * не добавляем повторно, строку вешаем на существующий ключ. Возвращает строки выдачи этих позиций.
     *
     * @return list<IssuanceLine>
     */
    private function materializePersonalItems(ProfileCompliance $profileCompliance, SaveDraftCommand $command, \DateTimeImmutable $documentDate): array
    {
        if ([] === $command->personalItems) {
            return [];
        }
        $requirementId = $this->requirementIdOfDocument($profileCompliance, $command->documentId);
        $lines = [];
        foreach ($command->personalItems as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ('' === $label) {
                continue;
            }
            $quantity = $this->mapper->quantityOf($row); // null, если поля кол-ва нет (нематериальный акт)
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
            $key = TrackedObligation::keyOf($requirementId, $label);
            if (!$this->hasObligation($profileCompliance, $key)) {
                $profileCompliance->addPersonalObligation(Uuid::v7(), $requirementId, $label, new Cadence(CadenceKind::ByManufacturerDoc), $quantity);
            }
            $lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due, $this->mapper->note($row), $this->mapper->instructionDetails($row));
        }

        return $lines;
    }

    /**
     * При подписи не материального требования с видом журнала — каждая строка обязана нести заполненные
     * обязательные поля инструктажа (схема {@see \App\Compliance\Domain\Type\JournalKind}). Правило — в домене
     * (JournalKind::assertDetailsComplete), хендлер только оркеструет: берёт вид журнала требования и зовёт.
     *
     * @param IssuanceLine[] $lines
     */
    private function assertInstructionComplete(ProfileCompliance $profileCompliance, string $documentId, array $lines): void
    {
        $requirementId = $this->requirementIdOfDocument($profileCompliance, $documentId);
        $journalKind = $this->requirements->findOneById($requirementId)?->getJournalKind();
        if (null === $journalKind) {
            return;
        }
        foreach ($lines as $line) {
            $journalKind->assertDetailsComplete($line->instructionDetails);
        }
    }

    private function hasObligation(ProfileCompliance $profileCompliance, string $key): bool
    {
        foreach ($profileCompliance->getObligations() as $obligation) {
            if ($obligation->key() === $key) {
                return true;
            }
        }

        return false;
    }

    private function requirementIdOfDocument(ProfileCompliance $profileCompliance, string $documentId): string
    {
        foreach ($profileCompliance->getDocuments() as $document) {
            if ($document->getId() === $documentId) {
                return $document->requirementId();
            }
        }

        throw new AppException('Черновик не найден.');
    }
}
