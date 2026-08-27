<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Порт Coatings→Certificates: чтение документов, привязанных к системам. Реализация — адаптер
 * в инфраструктуре Coatings, который читает Certificates. Направление одностороннее (ациклично).
 */
interface SystemCertificatesGateway
{
    public function hasCertificates(string $systemId): bool;

    /**
     * @return array<string, int> id системы → число привязанных документов
     */
    public function countBySystemIds(StringCollection $systemIds): array;

    /**
     * @return array<string, string> id системы → URL скачивания её документа (только у кого есть файл)
     */
    public function downloadUrlsBySystemIds(StringCollection $systemIds): array;

    /**
     * @return list<SystemCertificate>
     */
    public function listBySystem(string $systemId): array;
}
