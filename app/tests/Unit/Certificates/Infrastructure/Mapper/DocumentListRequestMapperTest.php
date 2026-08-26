<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Infrastructure\Mapper;

use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Repository\DocumentExpiryStatus;
use App\Certificates\Domain\Repository\DocumentSort;
use App\Certificates\Infrastructure\Mapper\DocumentListRequestMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DocumentListRequestMapperTest extends TestCase
{
    public function test_maps_all_query_params(): void
    {
        $request = new Request([
            'q' => '  ГОСТ  ',
            'kind' => DocumentKind::cases()[0]->value,
            'issuerId' => '01a03a3a-7673-7d52-b977-64be42e68bcc',
            'status' => 'expired',
            'testStandard' => 'ГОСТ Р 58346',
            'sort' => 'issuer_asc',
            'page' => '3',
        ]);

        $filter = (new DocumentListRequestMapper())->filterFromRequest($request);

        self::assertSame('ГОСТ', $filter->query);
        self::assertSame(DocumentKind::cases()[0], $filter->kind);
        self::assertSame('01a03a3a-7673-7d52-b977-64be42e68bcc', $filter->issuerId);
        self::assertSame(DocumentExpiryStatus::Expired, $filter->status);
        self::assertSame('ГОСТ Р 58346', $filter->testStandard);
        self::assertSame(DocumentSort::ISSUER_ASC, $filter->sort);
        self::assertNotNull($filter->pager);
        self::assertSame(3, $filter->pager->page);
    }

    public function test_empty_request_uses_defaults(): void
    {
        $filter = (new DocumentListRequestMapper())->filterFromRequest(new Request());

        self::assertNull($filter->query);
        self::assertNull($filter->kind);
        self::assertNull($filter->issuerId);
        self::assertNull($filter->status);
        self::assertNull($filter->testStandard);
        self::assertSame(DocumentSort::DEFAULT, $filter->sort);
        self::assertSame(1, $filter->pager->page);
    }

    public function test_invalid_enum_values_fall_back(): void
    {
        $request = new Request(['kind' => 'bogus', 'status' => 'bogus', 'sort' => 'bogus']);

        $filter = (new DocumentListRequestMapper())->filterFromRequest($request);

        self::assertNull($filter->kind);
        self::assertNull($filter->status);
        self::assertSame(DocumentSort::DEFAULT, $filter->sort);
    }
}
