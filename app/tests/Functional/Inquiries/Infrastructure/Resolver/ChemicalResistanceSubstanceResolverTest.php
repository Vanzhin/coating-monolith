<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Infrastructure\Resolver;

use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Inquiries\Application\Port\SubstanceResolver;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ChemicalResistanceSubstanceResolverTest extends KernelTestCase
{
    public function test_resolves_known_name_to_id_and_unknown_to_null(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $repo = $container->get(SubstanceRepositoryInterface::class);
        $spec = $container->get(SubstanceSpecification::class);
        $resolver = $container->get(SubstanceResolver::class);
        \assert($repo instanceof SubstanceRepositoryInterface);
        \assert($spec instanceof SubstanceSpecification);
        \assert($resolver instanceof SubstanceResolver);

        $id = Uuid::v4();
        $name = 'Этанол-'.bin2hex(random_bytes(4));
        $repo->add(new Substance($id, $name, null, new StringCollection(), $spec));

        self::assertSame($id->toRfc4122(), $resolver->resolve($name));
        self::assertNull($resolver->resolve('вещество-которого-нет-zzz'));
    }

    public function test_exact_match_beats_alphabetically_earlier_substring(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $repo = $container->get(SubstanceRepositoryInterface::class);
        $spec = $container->get(SubstanceSpecification::class);
        $resolver = $container->get(SubstanceResolver::class);
        \assert($repo instanceof SubstanceRepositoryInterface);
        \assert($spec instanceof SubstanceSpecification);
        \assert($resolver instanceof SubstanceResolver);

        // Воспроизводим баг «этанол → Диэтаноламин»: общая подстрока у обоих, но имя-container
        // по алфавиту раньше точного совпадения. Уникальный корень — чтобы не задеть сид-данные.
        $root = 'Ксенол'.bin2hex(random_bytes(3));
        $exactId = Uuid::v4();
        $repo->add(new Substance(Uuid::v4(), 'Ди'.$root.'амин', null, new StringCollection(), $spec));
        $repo->add(new Substance($exactId, $root, null, new StringCollection(), $spec));

        self::assertSame($exactId->toRfc4122(), $resolver->resolve($root));
    }
}
