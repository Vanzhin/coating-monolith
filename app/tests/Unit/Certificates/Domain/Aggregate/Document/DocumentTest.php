<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Domain\Aggregate\Document;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class DocumentTest extends TestCase
{
    /**
     * @param list<Reference>|null $references
     */
    private function make(
        ?string $title = null,
        ?string $subject = null,
        ?\DateTimeImmutable $issuedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?array $references = null,
    ): Document {
        return new Document(
            Uuid::v7(),
            DocumentKind::Conclusion,
            $title ?? '55-2023/ЦС ГСМ-ПК',
            Uuid::v7(),
            $issuedAt ?? new \DateTimeImmutable('2023-01-01'),
            $expiresAt,
            $subject ?? 'С5, 15-25 лет',
            'свободное описание',
            'ГОСТ Р 58346',
            null,
            ...($references ?? [new Reference(ReferenceType::CoatingSystem, Uuid::v7())]),
        );
    }

    public function test_valid_document_constructs(): void
    {
        $doc = $this->make();

        self::assertSame('55-2023/ЦС ГСМ-ПК', $doc->getTitle());
        self::assertSame('С5, 15-25 лет', $doc->getSubject());
        self::assertSame(DocumentKind::Conclusion, $doc->getKind());
        self::assertCount(1, $doc->references());
        self::assertNull($doc->getExpiresAt());
        self::assertFalse($doc->isExpired());
    }

    public function test_empty_title_throws(): void
    {
        $this->expectException(AppException::class);
        $this->make(title: '   ');
    }

    public function test_too_long_title_throws(): void
    {
        $this->expectException(AppException::class);
        $this->make(title: str_repeat('я', 256));
    }

    public function test_empty_subject_throws(): void
    {
        $this->expectException(AppException::class);
        $this->make(subject: '');
    }

    public function test_expires_before_issued_throws(): void
    {
        $this->expectException(AppException::class);
        $this->make(
            issuedAt: new \DateTimeImmutable('2023-05-01'),
            expiresAt: new \DateTimeImmutable('2023-01-01'),
        );
    }

    public function test_no_references_throws(): void
    {
        $this->expectException(AppException::class);
        $this->make(references: []);
    }

    public function test_duplicate_references_are_deduped(): void
    {
        $ref = new Reference(ReferenceType::CoatingSystem, Uuid::v7());
        $doc = $this->make(references: [$ref, new Reference($ref->referenceType, $ref->referenceId)]);

        self::assertCount(1, $doc->references());
    }

    public function test_is_expired_reflects_expires_at(): void
    {
        $doc = $this->make(
            issuedAt: new \DateTimeImmutable('2020-01-01'),
            expiresAt: new \DateTimeImmutable('2020-06-01'),
        );

        self::assertTrue($doc->isExpired(new \DateTimeImmutable('2021-01-01')));
        self::assertFalse($doc->isExpired(new \DateTimeImmutable('2020-03-01')));
    }

    public function test_add_reference_is_idempotent(): void
    {
        $doc = $this->make();
        $ref = new Reference(ReferenceType::Coating, Uuid::v7());

        $doc->addReference($ref);
        $doc->addReference(new Reference($ref->referenceType, $ref->referenceId));

        self::assertCount(2, $doc->references());
    }

    public function test_remove_reference(): void
    {
        $keep = new Reference(ReferenceType::CoatingSystem, Uuid::v7());
        $drop = new Reference(ReferenceType::Coating, Uuid::v7());
        $doc = $this->make(references: [$keep, $drop]);

        $doc->removeReference($drop);

        self::assertCount(1, $doc->references());
        self::assertTrue($doc->references()[0]->equals($keep));
    }

    public function test_remove_last_reference_throws(): void
    {
        $only = new Reference(ReferenceType::CoatingSystem, Uuid::v7());
        $doc = $this->make(references: [$only]);

        $this->expectException(AppException::class);
        $doc->removeReference($only);
    }

    public function test_references_to_filters_by_type(): void
    {
        $systemRef = new Reference(ReferenceType::CoatingSystem, Uuid::v7());
        $coatingRef = new Reference(ReferenceType::Coating, Uuid::v7());
        $doc = $this->make(references: [$systemRef, $coatingRef]);

        $systemIds = $doc->referencesTo(ReferenceType::CoatingSystem);

        self::assertCount(1, $systemIds);
        self::assertTrue($systemIds[0]->equals($systemRef->referenceId));
    }
}
