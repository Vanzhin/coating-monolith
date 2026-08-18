<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\CreateColor\CreateColorCommand;
use App\Coatings\Application\UseCase\Command\CreateColor\CreateColorCommandResult;
use App\Coatings\Domain\Aggregate\Color\RalClassicPalette;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateColorCommandHandlerTest extends KernelTestCase
{
    private CommandBusInterface $bus;
    private ColorRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->bus = $container->get(CommandBusInterface::class);
        $this->repo = $container->get(ColorRepositoryInterface::class);
    }

    public function test_creates_custom_color(): void
    {
        /** @var CreateColorCommandResult $result */
        $result = $this->bus->execute(new CreateColorCommand('Фирменный синий', null, '#123456'));

        self::assertNotSame('', $result->id);
        self::assertSame('#123456', $result->hex);
        self::assertNull($result->ral);
        self::assertNotNull($this->repo->findOneByNameAndHex('Фирменный синий', '#123456'));
    }

    public function test_creates_ral_color_with_derived_hex(): void
    {
        /** @var CreateColorCommandResult $result */
        $result = $this->bus->execute(new CreateColorCommand('Серый корпус', 'RAL 7040', null));

        self::assertSame('RAL 7040', $result->ral);
        self::assertSame(RalClassicPalette::require('RAL 7040')->hex->value, $result->hex);
    }

    public function test_rejects_duplicate_pair_name_and_hex(): void
    {
        $this->bus->execute(new CreateColorCommand('Серый', null, '#111111'));

        $this->expectException(AppException::class);
        $this->bus->execute(new CreateColorCommand('Серый', null, '#111111'));
    }

    public function test_allows_same_name_with_different_hex(): void
    {
        $this->bus->execute(new CreateColorCommand('Серый', null, '#111111'));
        /** @var CreateColorCommandResult $result */
        $result = $this->bus->execute(new CreateColorCommand('Серый', null, '#222222'));

        self::assertSame('#222222', $result->hex);
    }

    public function test_rejects_unknown_ral_code(): void
    {
        $this->expectException(AppException::class);
        $this->bus->execute(new CreateColorCommand('Что-то', 'RAL 0000', null));
    }
}
