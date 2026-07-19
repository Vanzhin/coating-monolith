<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Application\Service;

use App\ChemicalResistance\Application\Service\SubstanceLookup;
use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SubstanceLookupTest extends KernelTestCase
{
    private SubstanceLookup $lookup;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->lookup = $c->get(SubstanceLookup::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdIds as $id) {
                $e = $em->find(Substance::class, $id);
                if (null !== $e) {
                    $em->remove($e);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_creates_new_when_not_found(): void
    {
        $suffix = uniqid('lookup-new-', true);
        $raw = 'Новое Вещество '.$suffix;

        $substance = $this->lookup->findOrCreateByName($raw);
        $this->createdIds[] = $substance->id;

        self::assertSame($raw, $substance->getCanonicalName());
        self::assertNull($substance->getCas());
        self::assertCount(0, $substance->getAliases()->getList());

        // Persisted — clear cache and reload.
        $this->em->clear();
        $reloaded = $this->em->find(Substance::class, $substance->id);
        self::assertNotNull($reloaded);
        self::assertSame($raw, $reloaded->getCanonicalName());
    }

    public function test_reuses_by_canonical_key(): void
    {
        $suffix = uniqid('lookup-reuse-', true);
        $canonical = 'Вещество-'.$suffix;

        // Insert canonical substance.
        $first = $this->lookup->findOrCreateByName($canonical);
        $this->createdIds[] = $first->id;

        // Look up a variant with different case/whitespace.
        $variant = 'вещество-'.$suffix;
        $second = $this->lookup->findOrCreateByName($variant);

        self::assertSame($first->getId(), $second->getId(), 'Should return the same substance');

        // Alias was added for the variant spelling.
        $this->em->clear();
        $reloaded = $this->em->find(Substance::class, $first->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasName($variant), 'Variant spelling should be an alias');
    }

    public function test_reuses_by_cas(): void
    {
        $suffix = uniqid('lookup-cas-', true);
        $originalName = 'ВеществоCAS-'.$suffix;
        $cas = CasNumber::fromString('7732-18-5');

        // Insert substance with CAS.
        $first = $this->lookup->findOrCreateByName($originalName, $cas);
        $this->createdIds[] = $first->id;

        // Look up with a completely different name but same CAS.
        $differentName = 'ИноеНазвание-'.$suffix;
        $second = $this->lookup->findOrCreateByName($differentName, $cas);

        self::assertSame($first->getId(), $second->getId(), 'CAS match should return the same substance');

        // Alias was added for the different name.
        $this->em->clear();
        $reloaded = $this->em->find(Substance::class, $first->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasName($differentName), 'Different name should be added as alias');
    }
}
