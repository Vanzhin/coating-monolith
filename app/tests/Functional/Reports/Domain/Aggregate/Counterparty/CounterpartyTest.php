<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Domain\Aggregate\Counterparty;

use App\Reports\Domain\Aggregate\Counterparty\Counterparty;
use App\Reports\Domain\Aggregate\Counterparty\Specification\CounterpartySpecification;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CounterpartyTest extends KernelTestCase
{
    private CounterpartySpecification $specification;
    private CounterpartyRepositoryInterface $repository;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->specification = $container->get(CounterpartySpecification::class);
        $this->repository = $container->get(CounterpartyRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $c = $this->repository->findOneById($id);
                if (null !== $c) {
                    $this->repository->remove($c);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_create_persist_and_find(): void
    {
        $id = UuidService::generate();
        $counterparty = new Counterparty($id, 'ЕвроХим-УКК '.uniqid('', true), $this->specification, 'Заказчик');
        $this->repository->add($counterparty);
        $this->createdIds[] = $id;

        $found = $this->repository->findOneById($id);

        self::assertNotNull($found);
        self::assertSame($id, $found->getId());
        self::assertSame('Заказчик', $found->getDescription());
    }

    public function test_description_is_nullable(): void
    {
        $id = UuidService::generate();
        $counterparty = new Counterparty($id, 'БезОписания '.uniqid('', true), $this->specification);
        $this->repository->add($counterparty);
        $this->createdIds[] = $id;

        self::assertNull($this->repository->findOneById($id)?->getDescription());
    }

    public function test_duplicate_title_is_rejected(): void
    {
        $title = 'Дубль '.uniqid('', true);
        $first = new Counterparty(UuidService::generate(), $title, $this->specification);
        $this->repository->add($first);
        $this->createdIds[] = $first->getId();

        $this->expectException(AppException::class);
        new Counterparty(UuidService::generate(), $title, $this->specification);
    }

    public function test_too_long_title_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Counterparty(UuidService::generate(), str_repeat('я', 101), $this->specification);
    }
}
