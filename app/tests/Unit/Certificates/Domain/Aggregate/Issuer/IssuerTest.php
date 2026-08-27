<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Domain\Aggregate\Issuer;

use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Aggregate\Issuer\Specification\UniqueTitleIssuerSpecification;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Certificates\Domain\Repository\IssuersFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class IssuerTest extends TestCase
{
    public function test_valid_issuer_constructs(): void
    {
        $id = Uuid::v7();
        $issuer = new Issuer($id, 'НПЦ Самара', $this->spec());

        $this->assertSame((string) $id, $issuer->getId());
        $this->assertSame('НПЦ Самара', $issuer->getTitle());
    }

    public function test_title_is_trimmed(): void
    {
        $issuer = new Issuer(Uuid::v7(), '  ЦНИИТС  ', $this->spec());

        $this->assertSame('ЦНИИТС', $issuer->getTitle());
    }

    public function test_empty_title_throws(): void
    {
        $this->expectException(AppException::class);
        new Issuer(Uuid::v7(), '', $this->spec());
    }

    public function test_whitespace_only_title_throws(): void
    {
        $this->expectException(AppException::class);
        new Issuer(Uuid::v7(), "   \t", $this->spec());
    }

    public function test_too_long_title_throws(): void
    {
        $this->expectException(AppException::class);
        new Issuer(Uuid::v7(), str_repeat('я', 256), $this->spec());
    }

    public function test_duplicate_title_throws(): void
    {
        $existing = new Issuer(Uuid::v7(), 'НПЦ Самара', $this->spec());

        $this->expectException(AppException::class);
        new Issuer(Uuid::v7(), 'НПЦ Самара', $this->spec($existing));
    }

    public function test_same_id_same_title_is_allowed(): void
    {
        $id = Uuid::v7();
        $existing = new Issuer($id, 'НПЦ Самара', $this->spec());

        $again = new Issuer($id, 'НПЦ Самара', $this->spec($existing));

        $this->assertSame('НПЦ Самара', $again->getTitle());
    }

    private function spec(?Issuer $existingByTitle = null): IssuerSpecification
    {
        return new IssuerSpecification(new UniqueTitleIssuerSpecification($this->fakeRepo($existingByTitle)));
    }

    private function fakeRepo(?Issuer $existingByTitle): IssuerRepositoryInterface
    {
        return new class($existingByTitle) implements IssuerRepositoryInterface {
            public function __construct(private readonly ?Issuer $existingByTitle)
            {
            }

            public function add(Issuer $issuer): void
            {
            }

            public function remove(Issuer $issuer): void
            {
            }

            public function findOneById(string $id): ?Issuer
            {
                return null;
            }

            public function findOneByTitle(string $title): ?Issuer
            {
                return $this->existingByTitle;
            }

            public function findByIds(StringCollection $ids): array
            {
                return [];
            }

            public function findByFilter(IssuersFilter $filter): PaginationResult
            {
                throw new \LogicException('not needed in unit test');
            }

            public function suggest(string $query, int $limit = 10): array
            {
                return [];
            }
        };
    }
}
