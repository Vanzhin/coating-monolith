<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Domain\Service;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\Gloss;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Coatings\Domain\Service\CoatingMaker;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingMakerColorsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingMaker $maker;
    private ColorRepositoryInterface $colorRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->maker = $container->get(CoatingMaker::class);
        $this->colorRepository = $container->get(ColorRepositoryInterface::class);
    }

    public function test_maker_persists_possible_colors_gloss_and_tintable(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $manufacturer = $this->persistManufacturer($suffix);

        $ralColor = new Color(Uuid::v4(), 'Серый-'.$suffix, 'RAL 7040');
        $customColor = new Color(Uuid::v4(), 'Кастом-'.$suffix, null, '#123456');
        $this->colorRepository->add($ralColor);
        $this->colorRepository->add($customColor);

        $coating = $this->makeCoating(
            $manufacturer->getId(),
            $suffix,
            colorIds: [$ralColor->getId(), $customColor->getId()],
            gloss: Gloss::SEMI_GLOSS,
            isTintable: false,
        );
        $coatingUuid = $coating->id;
        $this->em->clear();

        $joinRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT color_id FROM coatings_coating_coating_color WHERE coating_id = ?',
            [(string) $coatingUuid],
        );
        self::assertCount(2, $joinRows);

        $loaded = $this->em->find(Coating::class, $coatingUuid);
        self::assertNotNull($loaded);
        self::assertCount(2, $loaded->getPossibleColors());
        self::assertSame(Gloss::SEMI_GLOSS, $loaded->getGloss());
        self::assertFalse($loaded->isTintable());
    }

    public function test_maker_allows_tintable_coating_without_colors(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $manufacturer = $this->persistManufacturer($suffix);

        $coating = $this->makeCoating($manufacturer->getId(), $suffix, colorIds: [], gloss: null, isTintable: true);
        $coatingUuid = $coating->id;
        $this->em->clear();

        $loaded = $this->em->find(Coating::class, $coatingUuid);
        self::assertNotNull($loaded);
        self::assertTrue($loaded->isTintable());
        self::assertCount(0, $loaded->getPossibleColors());
        self::assertNull($loaded->getGloss());
    }

    public function test_maker_rejects_non_tintable_coating_without_colors(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $manufacturer = $this->persistManufacturer($suffix);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/хотя бы один возможный цвет/u');
        $this->makeCoating($manufacturer->getId(), $suffix, colorIds: [], gloss: null, isTintable: false);
    }

    private function persistManufacturer(string $suffix): Manufacturer
    {
        $manufacturer = new Manufacturer(
            'Мфр-Colors-'.$suffix,
            static::getContainer()->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->em->flush();

        return $manufacturer;
    }

    /**
     * @param list<string> $colorIds
     */
    private function makeCoating(string $manufacturerId, string $suffix, array $colorIds, ?Gloss $gloss, bool $isTintable): Coating
    {
        return $this->maker->make(
            'Покрытие-Colors-'.$suffix,
            'Описание тестового покрытия.',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            null,
            $manufacturerId,
            new StringCollection(),
            1.0,
            null,
            colorIds: new StringCollection(...$colorIds),
            gloss: $gloss,
            isTintable: $isTintable,
        );
    }
}
