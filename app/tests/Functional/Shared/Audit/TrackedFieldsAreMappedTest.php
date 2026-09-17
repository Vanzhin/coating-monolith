<?php
declare(strict_types=1);
namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Domain\Audit\TrackedClass;
use App\Shared\Infrastructure\Audit\AuditableRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TrackedFieldsAreMappedTest extends KernelTestCase
{
    public function testEveryConfiguredFieldExistsInMetadata(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $registry = self::getContainer()->get(AuditableRegistry::class);

        $errors = [];
        foreach ($em->getRepository(TrackedClass::class)->findAll() as $tc) {
            $mapped = $registry->mappedFields($tc->entityClass());
            foreach (array_keys($tc->fields()) as $field) {
                if (!in_array($field, $mapped, true)) {
                    $errors[] = sprintf('%s: поле "%s" отсутствует в метаданных', $tc->entityClass(), $field);
                }
            }
        }

        self::assertSame([], $errors, implode("\n", $errors));
    }
}
