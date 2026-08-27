<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Console;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Infrastructure\Console\ImportConclusionsCommand;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class ImportConclusionsCommandTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;
    use AuthenticatesActorTrait;

    private EntityManagerInterface $em;
    private DocumentRepositoryInterface $documents;
    private IssuerRepositoryInterface $issuers;
    private ImportConclusionsCommand $command;
    private string $suffix = '';
    private string $tmpFile = '';

    private ?string $createdSystemId = null;
    private ?string $createdDocId = null;
    private ?string $createdIssuerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->documents = $container->get(DocumentRepositoryInterface::class);
        $this->issuers = $container->get(IssuerRepositoryInterface::class);
        $this->command = $container->get(ImportConclusionsCommand::class);
        $this->setUpFixture($container, $this->em);
        $this->suffix = bin2hex(random_bytes(3));

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            if (null !== $this->createdDocId) {
                $doc = $em->find(Document::class, Uuid::fromString($this->createdDocId));
                if (null !== $doc) {
                    $em->remove($doc);
                }
            }
            if (null !== $this->createdSystemId) {
                $sys = $em->find(CoatingSystem::class, Uuid::fromString($this->createdSystemId));
                if (null !== $sys) {
                    $em->remove($sys);
                }
            }
            if (null !== $this->createdIssuerId) {
                $issuer = $em->find(Issuer::class, Uuid::fromString($this->createdIssuerId));
                if (null !== $issuer) {
                    $em->remove($issuer);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'cleanup error: '.$e->getMessage()."\n");
        }
        if ('' !== $this->tmpFile && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        $this->tearDownFixture($em);
        parent::tearDown();
    }

    public function test_import_creates_system_and_document(): void
    {
        $coatingTitle = (string) $this->em->find(Coating::class, $this->coatingId)?->getTitle();
        $conclusion = 'TEST-CONCL-'.$this->suffix;
        $author = 'Тест-Автор-'.$this->suffix;
        $systemTitle = 'Система (закл. '.$conclusion.')';

        $this->tmpFile = tempnam(sys_get_temp_dir(), 'concl').'.json';
        file_put_contents($this->tmpFile, json_encode([[
            'conclusion' => $conclusion,
            'author' => $author,
            'issuedAt' => '2023-01-01',
            'expiresAt' => null,
            'subject' => 'С5, 15-25 лет',
            'description' => 'климатика',
            'substrate' => 'steel_carbon',
            'environment' => 'atmospheric',
            'systemTitle' => $systemTitle,
            'layers' => [['materials' => [$coatingTitle], 'dft' => '80']],
        ]], JSON_UNESCAPED_UNICODE));

        $tester = new CommandTester($this->command);
        $tester->execute(['--file' => $this->tmpFile]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();

        $system = $this->em->getRepository(CoatingSystem::class)->findOneBy(['title' => $systemTitle]);
        self::assertNotNull($system, 'Система должна быть создана.');
        $this->createdSystemId = $system->getId();
        self::assertSame(1, $system->layerCount());

        $docs = $this->documents->findByReference(new Reference(ReferenceType::CoatingSystem, Uuid::fromString($system->getId())));
        self::assertCount(1, $docs);
        $this->createdDocId = $docs[0]->getId();
        self::assertSame($conclusion, $docs[0]->getTitle());

        $issuer = $this->issuers->findOneByTitle($author);
        self::assertNotNull($issuer, 'Издатель должен быть создан по автору.');
        $this->createdIssuerId = $issuer->getId();
        self::assertSame($issuer->getId(), $docs[0]->getIssuerId());
    }
}
