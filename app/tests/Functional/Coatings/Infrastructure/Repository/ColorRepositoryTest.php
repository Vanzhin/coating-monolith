<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\Color\RalClassicPalette;
use App\Coatings\Infrastructure\Repository\ColorRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ColorRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ColorRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(ColorRepository::class);
    }

    public function test_persists_custom_color_and_reads_back(): void
    {
        $id = Uuid::v4();
        $this->repo->add(new Color($id, 'Фирменный синий', null, '#123abc'));
        $this->em->clear();

        $found = $this->repo->findOneById($id->toRfc4122());

        self::assertNotNull($found);
        self::assertSame('Фирменный синий', $found->getName());
        self::assertNull($found->getRal());
        self::assertSame('#123ABC', $found->getHex());
    }

    public function test_persists_ral_color_with_derived_hex(): void
    {
        $id = Uuid::v4();
        $this->repo->add(new Color($id, 'Серый корпус', 'RAL 7040'));
        $this->em->clear();

        $found = $this->repo->findOneById($id->toRfc4122());

        self::assertNotNull($found);
        self::assertSame('RAL 7040', $found->getRal());
        self::assertSame(RalClassicPalette::require('RAL 7040')->hex->value, $found->getHex());
    }

    public function test_find_one_by_name_and_hex_is_case_insensitive_on_name(): void
    {
        $this->repo->add(new Color(Uuid::v4(), 'Серый', null, '#111111'));
        $this->em->clear();

        self::assertNotNull($this->repo->findOneByNameAndHex('серый', '#111111'));
        self::assertNull($this->repo->findOneByNameAndHex('серый', '#222222'));
    }

    public function test_find_by_ids_returns_existing_colors(): void
    {
        $a = Uuid::v4();
        $b = Uuid::v4();
        $this->repo->add(new Color($a, 'Цвет A', null, '#0A0A0A'));
        $this->repo->add(new Color($b, 'Цвет B', null, '#0B0B0B'));
        $this->em->clear();

        $found = $this->repo->findByIds(new StringCollection($a->toRfc4122(), $b->toRfc4122()));

        self::assertCount(2, $found);
    }

    public function test_suggest_matches_by_name_and_by_ral_code(): void
    {
        $this->repo->add(new Color(Uuid::v4(), 'Мой оконный', 'RAL 7040'));
        $this->em->clear();

        self::assertCount(1, $this->repo->suggest('оконный', 10));
        self::assertCount(1, $this->repo->suggest('RAL 7040', 10));
        self::assertCount(1, $this->repo->suggest('7040', 10));
        self::assertCount(0, $this->repo->suggest('несуществующий', 10));
    }
}
