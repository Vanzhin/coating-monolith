<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Mapper;

use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Repository\DocumentExpiryStatus;
use App\Certificates\Domain\Repository\DocumentsFilter;
use App\Certificates\Domain\Repository\DocumentSort;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Query-параметры списка документов → DocumentsFilter. Pure shape.
 * Зеркаль CoatingSystemListRequestMapper (тот же контракт чипов/шторки).
 */
final class DocumentListRequestMapper
{
    private const DEFAULT_LIMIT = 30;

    public function filterFromRequest(Request $request): DocumentsFilter
    {
        $coatingIds = array_values(array_filter(
            array_map('strval', $request->query->all('coatingIds')),
            static fn (string $id): bool => Uuid::isValid($id),
        ));

        return new DocumentsFilter(
            pager: Pager::fromPage(max(1, (int) $request->query->get('page', 1)), self::DEFAULT_LIMIT),
            query: trim((string) $request->query->get('q', '')) ?: null,
            kind: DocumentKind::tryFrom((string) $request->query->get('kind', '')),
            issuerId: trim((string) $request->query->get('issuerId', '')) ?: null,
            status: DocumentExpiryStatus::tryFrom((string) $request->query->get('status', '')),
            testStandard: trim((string) $request->query->get('testStandard', '')) ?: null,
            sort: DocumentSort::tryFrom((string) $request->query->get('sort', '')) ?? DocumentSort::DEFAULT,
            coatingIds: [] !== $coatingIds ? new StringCollection(...$coatingIds) : null,
        );
    }
}
