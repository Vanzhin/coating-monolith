<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete\SubstanceAutocompleteQuery;
use App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete\SubstanceAutocompleteQueryHandler;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\DoctrineSubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SubstanceAutocompleteQueryHandlerTest extends KernelTestCase
{
    private SubstanceAutocompleteQueryHandler $handler;
    private DoctrineSubstanceRepository $substanceRepo;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $createdSubstanceIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->handler       = $c->get(SubstanceAutocompleteQueryHandler::class);
        $this->substanceRepo = $c->get(DoctrineSubstanceRepository::class);
        $this->em            = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdSubstanceIds as $id) {
                $e = $em->find(Substance::class, $id);
                if ($e !== null) {
                    $em->remove($e);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: ' . $e->getMessage() . "\n");
        }

        parent::tearDown();
    }

    /**
     * Seeds a substance with Russian canonical name and English alias.
     * Used by multiple tests to have consistent data.
     */
    private function seedWaterSubstance(): Uuid
    {
        $id = Uuid::v4();
        $substance = new Substance(
            $id,
            'Вода',
            '7732-18-5',
            new StringCollection('Water'),
            $this->substanceRepo->makeSpec(),
        );
        $this->substanceRepo->save($substance);
        $this->createdSubstanceIds[] = $id;
        $this->em->clear();
        return $id;
    }

    public function testFindsByRussianCanonicalPrefix(): void
    {
        $this->seedWaterSubstance();

        $result = ($this->handler)(new SubstanceAutocompleteQuery('вод', 10));

        self::assertNotEmpty($result, 'Query for "вод" should return at least one match.');
        self::assertIsArray($result);
        self::assertGreaterThanOrEqual(1, count($result));

        $found = false;
        foreach ($result as $dto) {
            self::assertInstanceOf(SubstanceDTO::class, $dto);
            if (str_contains(mb_strtolower($dto->canonicalName), 'вод')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected to find a substance with "вод" in canonical name.');
    }

    public function testFindsByCasExact(): void
    {
        $this->seedWaterSubstance();

        $result = ($this->handler)(new SubstanceAutocompleteQuery('7732-18-5', 10));

        self::assertNotEmpty($result, 'Query for exact CAS "7732-18-5" should return at least one match.');

        $found = false;
        foreach ($result as $dto) {
            if ($dto->cas === '7732-18-5') {
                $found = true;
                self::assertStringContainsString('Вода', $dto->canonicalName);
                break;
            }
        }
        self::assertTrue($found, 'Expected to find substance with CAS "7732-18-5".');
    }

    public function testFindsByAliasPrefix(): void
    {
        $this->seedWaterSubstance();

        $result = ($this->handler)(new SubstanceAutocompleteQuery('wate', 10));

        self::assertNotEmpty($result, 'Query for alias prefix "wate" should return at least one match.');

        $found = false;
        foreach ($result as $dto) {
            foreach ($dto->aliases as $alias) {
                if (str_contains(mb_strtolower($alias), 'wate')) {
                    $found = true;
                    self::assertStringContainsString('Вода', $dto->canonicalName);
                    break 2;
                }
            }
        }
        self::assertTrue($found, 'Expected to find substance with alias containing "wate".');
    }

    public function testEmptyQueryReturnsEmpty(): void
    {
        $this->seedWaterSubstance();

        $result = ($this->handler)(new SubstanceAutocompleteQuery('', 10));

        self::assertEmpty($result, 'Empty query string should return empty array.');
    }

    public function testWhitespaceOnlyQueryReturnsEmpty(): void
    {
        $this->seedWaterSubstance();

        $result = ($this->handler)(new SubstanceAutocompleteQuery('   ', 10));

        self::assertEmpty($result, 'Whitespace-only query should return empty array.');
    }

    public function testRespectsLimit(): void
    {
        // Seed multiple substances
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $id = Uuid::v4();
            $substance = new Substance(
                $id,
                'Вещество-' . $i,
                null,
                new StringCollection(),
                $this->substanceRepo->makeSpec(),
            );
            $this->substanceRepo->save($substance);
            $ids[] = $id;
        }
        $this->em->clear();

        $result = ($this->handler)(new SubstanceAutocompleteQuery('вещ', 3));

        self::assertLessThanOrEqual(3, count($result),
            'Result count must not exceed the specified limit.');
    }
}
