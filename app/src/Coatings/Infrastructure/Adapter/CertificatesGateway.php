<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Adapter;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Coatings\Domain\Service\SystemCertificate;
use App\Coatings\Domain\Service\SystemCertificatesGateway;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Адаптер порта Coatings→Certificates. Coatings-инфра зависит от домена Certificates через
 * интерфейсы; Certificates про Coatings ничего не знает (ациклично).
 */
final readonly class CertificatesGateway implements SystemCertificatesGateway
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private IssuerRepositoryInterface $issuers,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function hasCertificates(string $systemId): bool
    {
        $counts = $this->documents->countByReferences(ReferenceType::CoatingSystem, new StringCollection($systemId));

        return ($counts[$systemId] ?? 0) > 0;
    }

    public function countBySystemIds(StringCollection $systemIds): array
    {
        if (0 === $systemIds->count()) {
            return [];
        }

        return $this->documents->countByReferences(ReferenceType::CoatingSystem, $systemIds);
    }

    public function listBySystem(string $systemId): array
    {
        $documents = $this->documents->findByReference(
            new Reference(ReferenceType::CoatingSystem, Uuid::fromString($systemId)),
        );
        if ([] === $documents) {
            return [];
        }

        $issuerTitles = $this->issuerTitles($documents);

        return array_map(
            fn (Document $document) => $this->toSystemCertificate($document, $issuerTitles),
            $documents,
        );
    }

    /**
     * @param list<Document> $documents
     *
     * @return array<string, string>
     */
    private function issuerTitles(array $documents): array
    {
        $ids = array_values(array_unique(array_map(static fn (Document $d) => $d->getIssuerId(), $documents)));
        $titles = [];
        foreach ($this->issuers->findByIds(new StringCollection(...$ids)) as $issuer) {
            $titles[$issuer->getId()] = $issuer->getTitle();
        }

        return $titles;
    }

    /**
     * @param array<string, string> $issuerTitles
     */
    private function toSystemCertificate(Document $document, array $issuerTitles): SystemCertificate
    {
        $downloadUrl = null !== $document->getFile()
            ? $this->urlGenerator->generate('app_cabinet_certificate_document_download', ['id' => $document->getId()])
            : null;

        return new SystemCertificate(
            $document->getId(),
            $document->getTitle(),
            $document->getKind()->label(),
            $issuerTitles[$document->getIssuerId()] ?? null,
            $document->getIssuedAt(),
            $document->getExpiresAt(),
            $document->isExpired(),
            $document->getTestStandard(),
            null !== $document->getFile(),
            $downloadUrl,
        );
    }
}
