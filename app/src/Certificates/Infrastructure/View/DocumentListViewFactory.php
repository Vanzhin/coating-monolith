<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\View;

use App\Certificates\Application\UseCase\Query\GetPagedDocuments\GetPagedDocumentsQueryResult;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Repository\DocumentExpiryStatus;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\DocumentSort;
use Symfony\Component\HttpFoundation\Request;

/**
 * Full render-payload списка документов: echo-back фильтров + опции фасетов + счётчики.
 * Зеркаль CoatingSystemListViewFactory. Опции testStandard — из репозитория (distinct).
 */
final class DocumentListViewFactory
{
    private const DEFAULT_LIMIT = 30;

    public function __construct(private readonly DocumentRepositoryInterface $documents)
    {
    }

    /**
     * @param list<object> $issuers объекты с ->id / ->title
     *
     * @return array<string, mixed>
     */
    public function build(Request $request, GetPagedDocumentsQueryResult $result, array $issuers): array
    {
        $kind = (string) $request->query->get('kind', '');
        $issuerId = trim((string) $request->query->get('issuerId', ''));
        $status = (string) $request->query->get('status', '');
        $testStandard = trim((string) $request->query->get('testStandard', ''));

        $coatingIds = array_values(array_filter(
            array_map('strval', $request->query->all('coatingIds')),
            static fn (string $id): bool => '' !== $id,
        ));

        $activeFacetsCount = ('' !== $kind ? 1 : 0)
            + ('' !== $issuerId ? 1 : 0)
            + ('' !== $status ? 1 : 0)
            + ('' !== $testStandard ? 1 : 0)
            + \count($coatingIds);

        return [
            'documents' => $result->documents,
            'pager' => $result->pager,
            'total' => $result->pager->total_items ?? 0,
            'page' => max(1, (int) $request->query->get('page', 1)),
            'perPage' => $result->pager->perPage ?: self::DEFAULT_LIMIT,
            'q' => trim((string) $request->query->get('q', '')),
            'kind' => $kind,
            'issuerId' => $issuerId,
            'status' => $status,
            'testStandard' => $testStandard,
            'coatingIds' => $coatingIds,
            'sort' => DocumentSort::tryFrom((string) $request->query->get('sort', '')) ?? DocumentSort::DEFAULT,
            'activeFacetsCount' => $activeFacetsCount,
            'kinds' => DocumentKind::cases(),
            'issuers' => $issuers,
            'statusOptions' => DocumentExpiryStatus::cases(),
            'sortOptions' => DocumentSort::cases(),
            'testStandards' => $this->documents->distinctTestStandards(),
        ];
    }
}
