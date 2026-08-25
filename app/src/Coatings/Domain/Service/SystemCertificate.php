<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

/**
 * Read-VO Coatings: документ (сертификат/заключение), привязанный к системе. Собирается
 * адаптером из контекста Certificates, но выражен в собственных типах Coatings — домен
 * Coatings не знает о классах Certificates.
 */
final readonly class SystemCertificate
{
    public function __construct(
        public string $id,
        public string $title,
        public string $kindLabel,
        public ?string $issuerTitle,
        public \DateTimeImmutable $issuedAt,
        public ?\DateTimeImmutable $expiresAt,
        public bool $isExpired,
        public ?string $testStandard,
        public bool $hasFile,
        public ?string $downloadUrl,
    ) {
    }
}
