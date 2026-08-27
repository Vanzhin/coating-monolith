<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Aggregate\Document;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Подтверждающий документ (заключение / сертификат / протокол), прикрученный к одному
 * или нескольким владельцам (системам/покрытиям) через коллекцию мягких ссылок Reference.
 */
class Document extends Aggregate
{
    private const MAX_TITLE_LENGTH = 255;
    private const MAX_SUBJECT_LENGTH = 1000;
    private const MAX_DESCRIPTION_LENGTH = 2000;
    private const MAX_TEST_STANDARD_LENGTH = 255;

    public readonly Uuid $id;

    /** @var list<Reference> */
    private array $references = [];
    private DocumentKind $kind;
    private string $title;
    private Uuid $issuerId;
    private \DateTimeImmutable $issuedAt;
    private ?\DateTimeImmutable $expiresAt = null;
    private string $subject;
    private ?string $description = null;
    private ?string $testStandard = null;
    private ?string $file = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        DocumentKind $kind,
        string $title,
        Uuid $issuerId,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $expiresAt,
        string $subject,
        ?string $description,
        ?string $testStandard,
        ?string $file,
        Reference ...$references,
    ) {
        $this->id = $id;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->kind = $kind;
        $this->issuerId = $issuerId;
        $this->setTitle($title);
        $this->setDates($issuedAt, $expiresAt);
        $this->setSubject($subject);
        $this->setDescription($description);
        $this->setTestStandard($testStandard);
        $this->setFile($file);
        $this->replaceReferences(...$references);
        // «Только что создан» — updatedAt равен createdAt, несмотря на touch() в сеттерах.
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getKind(): DocumentKind
    {
        return $this->kind;
    }

    public function setKind(DocumentKind $kind): void
    {
        $this->kind = $kind;
        $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $trimmed = trim($title);
        if ('' === $trimmed) {
            throw new AppException('Номер/название документа не может быть пустым.');
        }
        if (mb_strlen($trimmed) > self::MAX_TITLE_LENGTH) {
            throw new AppException(sprintf('Номер/название документа не должно превышать %d символов.', self::MAX_TITLE_LENGTH));
        }
        $this->title = $trimmed;
        $this->touch();
    }

    public function getIssuerId(): string
    {
        return (string) $this->issuerId;
    }

    public function setIssuerId(Uuid $issuerId): void
    {
        $this->issuerId = $issuerId;
        $this->touch();
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setDates(\DateTimeImmutable $issuedAt, ?\DateTimeImmutable $expiresAt): void
    {
        if (null !== $expiresAt && $expiresAt < $issuedAt) {
            throw new AppException('Срок действия документа не может быть раньше даты выдачи.');
        }
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
        $this->touch();
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return $this->expiresAt < ($now ?? new \DateTimeImmutable());
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $trimmed = trim($subject);
        if ('' === $trimmed) {
            throw new AppException('Поле «что подтверждает» не может быть пустым.');
        }
        if (mb_strlen($trimmed) > self::MAX_SUBJECT_LENGTH) {
            throw new AppException(sprintf('Поле «что подтверждает» не должно превышать %d символов.', self::MAX_SUBJECT_LENGTH));
        }
        $this->subject = $trimmed;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $trimmed = null !== $description ? trim($description) : null;
        if (null !== $trimmed && mb_strlen($trimmed) > self::MAX_DESCRIPTION_LENGTH) {
            throw new AppException(sprintf('Описание не должно превышать %d символов.', self::MAX_DESCRIPTION_LENGTH));
        }
        $this->description = ('' === $trimmed) ? null : $trimmed;
        $this->touch();
    }

    public function getTestStandard(): ?string
    {
        return $this->testStandard;
    }

    public function setTestStandard(?string $testStandard): void
    {
        $trimmed = null !== $testStandard ? trim($testStandard) : null;
        if (null !== $trimmed && mb_strlen($trimmed) > self::MAX_TEST_STANDARD_LENGTH) {
            throw new AppException(sprintf('Стандарт испытания не должен превышать %d символов.', self::MAX_TEST_STANDARD_LENGTH));
        }
        $this->testStandard = ('' === $trimmed) ? null : $trimmed;
        $this->touch();
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(?string $file): void
    {
        $this->file = $file;
        $this->touch();
    }

    /**
     * @return list<Reference>
     */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * @return list<Uuid>
     */
    public function referencesTo(ReferenceType $type): array
    {
        $ids = [];
        foreach ($this->references as $reference) {
            if ($reference->referenceType === $type) {
                $ids[] = $reference->referenceId;
            }
        }

        return $ids;
    }

    public function addReference(Reference $reference): void
    {
        foreach ($this->references as $existing) {
            if ($existing->equals($reference)) {
                return;
            }
        }
        $this->references[] = $reference;
        $this->touch();
    }

    public function removeReference(Reference $reference): void
    {
        $filtered = array_values(array_filter(
            $this->references,
            static fn (Reference $r) => !$r->equals($reference),
        ));
        if ([] === $filtered) {
            throw new AppException('Нельзя убрать последнюю ссылку: документ должен ссылаться хотя бы на одну сущность.');
        }
        $this->references = $filtered;
        $this->touch();
    }

    public function replaceReferences(Reference ...$references): void
    {
        $unique = [];
        foreach ($references as $reference) {
            foreach ($unique as $seen) {
                if ($seen->equals($reference)) {
                    continue 2;
                }
            }
            $unique[] = $reference;
        }
        if ([] === $unique) {
            throw new AppException('Документ должен ссылаться хотя бы на одну сущность.');
        }
        $this->references = $unique;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
