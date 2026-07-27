<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\SurfaceTreatment;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SurfaceTreatmentTest extends TestCase
{
    // --- happy path ---

    public function test_construction_happy_path(): void
    {
        $id = Uuid::v7();
        $st = new SurfaceTreatment(
            id: $id,
            description: 'Abrasive blast cleaning',
            code: 'Sa 2.5',
            standardCode: 'ISO 8501-1',
            substrateScope: [Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED],
        );

        self::assertSame((string) $id, $st->getId());
        self::assertSame('Abrasive blast cleaning', $st->getDescription());
        self::assertSame('Sa 2.5', $st->getCode());
        self::assertSame('ISO 8501-1', $st->getStandardCode());
        self::assertSame([Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED], $st->getSubstrateScope());
        self::assertNotNull($st->getCreatedAt());
        self::assertEquals($st->getCreatedAt(), $st->getUpdatedAt());
    }

    public function test_construction_with_null_optional_fields(): void
    {
        $st = new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Sweep blast',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::CONCRETE],
        );

        self::assertNull($st->getCode());
        self::assertNull($st->getStandardCode());
    }

    public function test_construction_created_at_equals_updated_at(): void
    {
        $st = $this->newSurfaceTreatment();
        self::assertEquals($st->getCreatedAt(), $st->getUpdatedAt());
    }

    // --- description invariants ---

    public function test_empty_description_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: '',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );
    }

    public function test_whitespace_only_description_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: '   ',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );
    }

    public function test_description_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: str_repeat('x', 2001),
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );
    }

    public function test_description_max_length_ok(): void
    {
        $st = new SurfaceTreatment(
            id: Uuid::v7(),
            description: str_repeat('x', 2000),
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );
        self::assertSame(2000, mb_strlen($st->getDescription()));
    }

    // --- code invariants ---

    public function test_empty_code_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: '',
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );
    }

    public function test_code_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: str_repeat('x', 31),
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );
    }

    public function test_code_max_length_ok(): void
    {
        $st = new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: str_repeat('x', 30),
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );
        self::assertSame(30, mb_strlen($st->getCode()));
    }

    public function test_null_code_ok(): void
    {
        $st = $this->newSurfaceTreatment(code: null);
        self::assertNull($st->getCode());
    }

    // --- standardCode invariants ---

    public function test_empty_standard_code_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: null,
            standardCode: '',
            substrateScope: [Substrate::STEEL_CARBON],
        );
    }

    public function test_standard_code_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: null,
            standardCode: str_repeat('x', 101),
            substrateScope: [Substrate::STEEL_CARBON],
        );
    }

    public function test_standard_code_max_length_ok(): void
    {
        $st = new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: null,
            standardCode: str_repeat('x', 100),
            substrateScope: [Substrate::STEEL_CARBON],
        );
        self::assertSame(100, mb_strlen($st->getStandardCode()));
    }

    public function test_null_standard_code_ok(): void
    {
        $st = $this->newSurfaceTreatment(standardCode: null);
        self::assertNull($st->getStandardCode());
    }

    // --- substrateScope invariants ---

    public function test_empty_substrate_scope_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: null,
            standardCode: null,
            substrateScope: [],
        );
    }

    public function test_duplicate_substrate_in_scope_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON, Substrate::STEEL_CARBON],
        );
    }

    // --- supportsSubstrate ---

    public function test_supports_substrate_returns_true_for_scope_member(): void
    {
        $st = new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON, Substrate::CONCRETE],
        );

        self::assertTrue($st->supportsSubstrate(Substrate::STEEL_CARBON));
        self::assertTrue($st->supportsSubstrate(Substrate::CONCRETE));
    }

    public function test_supports_substrate_returns_false_for_out_of_scope(): void
    {
        $st = new SurfaceTreatment(
            id: Uuid::v7(),
            description: 'Valid description',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON],
        );

        self::assertFalse($st->supportsSubstrate(Substrate::ALUMINUM));
        self::assertFalse($st->supportsSubstrate(Substrate::CONCRETE));
    }

    // --- setter updatedAt ---

    public function test_set_description_updates_updated_at(): void
    {
        $st = $this->newSurfaceTreatment();
        $before = $st->getUpdatedAt();
        usleep(2000);
        $st->setDescription('Updated description');
        $after = $st->getUpdatedAt();
        self::assertGreaterThan($before, $after);
    }

    public function test_set_code_updates_updated_at(): void
    {
        $st = $this->newSurfaceTreatment();
        $before = $st->getUpdatedAt();
        usleep(2000);
        $st->setCode('St 3');
        $after = $st->getUpdatedAt();
        self::assertGreaterThan($before, $after);
    }

    public function test_set_standard_code_updates_updated_at(): void
    {
        $st = $this->newSurfaceTreatment();
        $before = $st->getUpdatedAt();
        usleep(2000);
        $st->setStandardCode('SSPC-SP 6');
        $after = $st->getUpdatedAt();
        self::assertGreaterThan($before, $after);
    }

    public function test_set_substrate_scope_updates_updated_at(): void
    {
        $st = $this->newSurfaceTreatment();
        $before = $st->getUpdatedAt();
        usleep(2000);
        $st->setSubstrateScope([Substrate::ALUMINUM]);
        $after = $st->getUpdatedAt();
        self::assertGreaterThan($before, $after);
    }

    // --- helpers ---

    /**
     * @param list<Substrate>|null $substrateScope
     */
    private function newSurfaceTreatment(
        ?string $description = 'Abrasive blast cleaning',
        ?string $code = 'Sa 2.5',
        ?string $standardCode = 'ISO 8501-1',
        ?array $substrateScope = null,
    ): SurfaceTreatment {
        return new SurfaceTreatment(
            id: Uuid::v7(),
            description: $description ?? 'Abrasive blast cleaning',
            code: $code,
            standardCode: $standardCode,
            substrateScope: $substrateScope ?? [Substrate::STEEL_CARBON],
        );
    }
}
