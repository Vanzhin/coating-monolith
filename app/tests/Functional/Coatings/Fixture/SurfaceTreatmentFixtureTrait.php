<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Fixture;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Provides a helper to create and persist a test SurfaceTreatment that covers all substrates.
 * Call cleanUpTreatment() in tearDown to remove it.
 */
trait SurfaceTreatmentFixtureTrait
{
    private ?Uuid $treatmentId = null;

    private function createAndPersistTreatment(EntityManagerInterface $em, ?string $suffix = null): SurfaceTreatment
    {
        $id = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $id,
            'Тестовая подготовка поверхности'.($suffix ? ' '.$suffix : ''),
            $suffix ? substr('ST-'.$suffix, 0, 30) : 'Sa-test',
            null,
            Substrate::cases(),
        );
        $em->persist($treatment);
        $em->flush();
        $this->treatmentId = $id;

        return $treatment;
    }

    private function cleanUpTreatment(EntityManagerInterface $em): void
    {
        if (null !== $this->treatmentId) {
            $t = $em->find(SurfaceTreatment::class, $this->treatmentId);
            if (null !== $t) {
                $em->remove($t);
                $em->flush();
            }
            $this->treatmentId = null;
        }
    }
}
