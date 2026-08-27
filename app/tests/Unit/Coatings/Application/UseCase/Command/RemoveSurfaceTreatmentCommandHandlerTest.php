<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\UseCase\Command;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Application\UseCase\Command\RemoveSurfaceTreatment\RemoveSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\RemoveSurfaceTreatment\RemoveSurfaceTreatmentCommandHandler;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Shared\Application\Security\AccessGuard;
use App\Shared\Application\Security\AuthChecker;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

final class RemoveSurfaceTreatmentCommandHandlerTest extends TestCase
{
    public function test_throws_when_used_in_coating_systems(): void
    {
        $id = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $id,
            'Дробеструйная Sa2½',
            'Sa2½',
            null,
            [Substrate::STEEL_CARBON],
        );

        $treatmentRepo = new class($id, $treatment) implements SurfaceTreatmentRepositoryInterface {
            public function __construct(private Uuid $id, private SurfaceTreatment $treatment)
            {
            }

            public function save(SurfaceTreatment $t): void
            {
            }

            public function remove(SurfaceTreatment $t): void
            {
            }

            public function findById(Uuid $id): ?SurfaceTreatment
            {
                return $this->id->equals($id) ? $this->treatment : null;
            }

            public function list(\App\Coatings\Domain\Repository\SurfaceTreatmentsFilter $filter, int $limit, int $offset): array
            {
                return [];
            }

            public function count(\App\Coatings\Domain\Repository\SurfaceTreatmentsFilter $filter): int
            {
                return 0;
            }
        };

        $coatingSystemRepo = new class() implements CoatingSystemRepositoryInterface {
            public function save(CoatingSystem $system): void
            {
            }

            public function remove(CoatingSystem $system): void
            {
            }

            public function findById(Uuid $id): ?CoatingSystem
            {
                return null;
            }

            public function countUsingSurfaceTreatment(string $treatmentId): int
            {
                return 3;
            }

            public function findByLayerCoatingId(string $coatingId): array
            {
                return [];
            }

            public function findSystemTitlesByCoatingIds(StringCollection $coatingIds): array
            {
                return [];
            }

            public function findAll(): array
            {
                return [];
            }

            public function findByIds(StringCollection $ids): array
            {
                return [];
            }
        };

        // Авторизация проходит (админ/система) — проверяем доменное правило, а не гейт.
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $access = new CoatingAccessControl(new AccessGuard(new AuthChecker($authorizationChecker)));

        $handler = new RemoveSurfaceTreatmentCommandHandler($treatmentRepo, $coatingSystemRepo, $access);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/используется в 3 системах/');

        $handler(new RemoveSurfaceTreatmentCommand((string) $id));
    }
}
