<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommandHandler;
use App\Coatings\Application\UseCase\Query\GetCoatingColors\GetCoatingColorsQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingColors\GetCoatingColorsQueryResult;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CreateCoatingSystemColorTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;
    use AuthenticatesActorTrait;

    private CreateCoatingSystemCommandHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(CreateCoatingSystemCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->authenticateAsSystem();
    }

    public function test_create_persists_layer_color(): void
    {
        $suffix = bin2hex(random_bytes(3));
        [$coatingId, $color] = $this->coatingWithColor($suffix, tintable: false);
        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $result = ($this->handler)(new CreateCoatingSystemCommand(
            title: 'Система-Цвет-'.$suffix,
            description: '',
            substrate: Substrate::STEEL_CARBON,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
            initialLayers: [
                ['coatingId' => $coatingId, 'dft' => 80, 'colorId' => $color->getId()],
            ],
        ));

        $this->em->clear();
        $system = $this->em->find(CoatingSystem::class, Uuid::fromString($result->id));
        self::assertNotNull($system);
        $layer = $system->getLayers()->toArray()[0];
        self::assertNotNull($layer->getColor());
        self::assertSame($color->getId(), $layer->getColor()->getId());
    }

    public function test_create_rejects_foreign_layer_color_on_non_tintable(): void
    {
        $suffix = bin2hex(random_bytes(3));
        [$coatingId] = $this->coatingWithColor($suffix, tintable: false);
        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $foreign = new Color(Uuid::v4(), 'Чужой-'.$suffix, null, '#654321');
        static::getContainer()->get(ColorRepositoryInterface::class)->add($foreign);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не входит в возможные цвета/u');
        ($this->handler)(new CreateCoatingSystemCommand(
            title: 'Система-Чужой-'.$suffix,
            description: '',
            substrate: Substrate::STEEL_CARBON,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
            initialLayers: [
                ['coatingId' => $coatingId, 'dft' => 80, 'colorId' => $foreign->getId()],
            ],
        ));
    }

    public function test_get_coating_colors_query_returns_tintable_flag_and_colors(): void
    {
        $suffix = bin2hex(random_bytes(3));
        [$coatingId, $color] = $this->coatingWithColor($suffix, tintable: false);

        /** @var GetCoatingColorsQueryResult $result */
        $result = static::getContainer()->get(QueryBusInterface::class)->execute(new GetCoatingColorsQuery($coatingId));

        self::assertFalse($result->isTintable);
        self::assertCount(1, $result->colors);
        self::assertSame($color->getId(), $result->colors[0]->id);
        self::assertSame($color->label(), $result->colors[0]->label);
    }

    /**
     * @return array{0: string, 1: Color}
     */
    private function coatingWithColor(string $suffix, bool $tintable): array
    {
        $container = static::getContainer();

        $manufacturer = new Manufacturer('Мфр-'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($manufacturer);

        $color = new Color(Uuid::v4(), 'Серый-'.$suffix, 'RAL 7040');
        $container->get(ColorRepositoryInterface::class)->add($color);

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-'.$suffix,
            'desc',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            null,
            1.0,
            null,
            $manufacturer,
            $container->get(CoatingSpecification::class),
        );
        $coating->applyColorScheme($tintable, $color);
        $this->em->persist($coating);
        $this->em->flush();

        return [(string) $coatingId, $color];
    }
}
