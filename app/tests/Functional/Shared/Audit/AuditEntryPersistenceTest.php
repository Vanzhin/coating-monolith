<?php
declare(strict_types=1);
namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Domain\Audit\{AuditAction, AuditEntry, ChangeSet, FieldChange};
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditEntryPersistenceTest extends KernelTestCase
{
    public function testPersistAndReload(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $id = Uuid::uuid4()->toString();
        $em->persist(new AuditEntry($id, 'App\\X', 'c1', AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'X', 'Y')), 'ulid', new \DateTimeImmutable()));
        $em->flush();
        $em->clear();
        $loaded = $em->find(AuditEntry::class, $id);
        self::assertSame('title', $loaded->changes()->all()[0]->path);
    }
}
