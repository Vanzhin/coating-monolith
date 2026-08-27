<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificates\Application\UseCase;

use App\Certificates\Application\UseCase\Command\CreateDocument\CreateDocumentCommand;
use App\Certificates\Application\UseCase\Command\CreateDocument\CreateDocumentCommandResult;
use App\Certificates\Application\UseCase\Command\DeleteDocument\DeleteDocumentCommand;
use App\Certificates\Application\UseCase\Command\UpdateDocument\UpdateDocumentCommand;
use App\Certificates\Application\UseCase\Query\GetDocument\GetDocumentQuery;
use App\Certificates\Application\UseCase\Query\GetDocument\GetDocumentQueryResult;
use App\Certificates\Application\UseCase\Query\GetPagedDocuments\GetPagedDocumentsQuery;
use App\Certificates\Application\UseCase\Query\GetPagedDocuments\GetPagedDocumentsQueryResult;
use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\DocumentsFilter;
use App\Certificates\Infrastructure\Storage\DocumentFileStorage;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class DocumentUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private DocumentRepositoryInterface $repo;
    private DocumentFileStorage $storage;
    private IssuerSpecification $issuerSpec;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $createdDocIds = [];
    /** @var list<Uuid> */
    private array $createdIssuerIds = [];
    /** @var list<string> */
    private array $writtenFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->repo = $c->get(DocumentRepositoryInterface::class);
        $this->storage = $c->get(DocumentFileStorage::class);
        $this->issuerSpec = $c->get(IssuerSpecification::class);
        $this->em = $c->get(EntityManagerInterface::class);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdDocIds as $id) {
                $doc = $em->find(Document::class, Uuid::fromString($id));
                if (null !== $doc) {
                    $em->remove($doc);
                }
            }
            foreach ($this->createdIssuerIds as $id) {
                $issuer = $em->find(Issuer::class, $id);
                if (null !== $issuer) {
                    $em->remove($issuer);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        foreach ($this->writtenFiles as $file) {
            $this->storage->delete($file);
        }
        parent::tearDown();
    }

    private function makeIssuer(string $title): string
    {
        $id = Uuid::v7();
        $issuer = new Issuer($id, $title, $this->issuerSpec);
        $this->em->persist($issuer);
        $this->em->flush();
        $this->createdIssuerIds[] = $id;

        return (string) $id;
    }

    /**
     * @param list<Reference> $refs
     */
    private function createCmd(
        string $issuerId,
        array $refs,
        string $title = 'DOC-1',
        DocumentKind $kind = DocumentKind::Conclusion,
        ?UploadedFile $file = null,
    ): CreateDocumentCommand {
        return new CreateDocumentCommand(
            $kind,
            $title,
            $issuerId,
            new \DateTimeImmutable('2023-01-01'),
            null,
            'С5, 15-25 лет',
            'описание',
            'ГОСТ Р 58346',
            $refs,
            $file,
        );
    }

    private function create(CreateDocumentCommand $cmd): string
    {
        $result = $this->commandBus->execute($cmd);
        \assert($result instanceof CreateDocumentCommandResult);
        $this->createdDocIds[] = $result->id;

        return $result->id;
    }

    private function pdfUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scan').'.pdf';
        file_put_contents($path, "%PDF-1.4\ntest\n%%EOF");

        return new UploadedFile($path, 'scan.pdf', 'application/pdf', null, true);
    }

    public function test_create_persists_references_and_kind(): void
    {
        $issuer = $this->makeIssuer('НПЦ-'.bin2hex(random_bytes(3)));
        $id = $this->create($this->createCmd($issuer, [
            new Reference(ReferenceType::CoatingSystem, Uuid::v7()),
            new Reference(ReferenceType::Coating, Uuid::v7()),
        ], kind: DocumentKind::Certificate));

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame(DocumentKind::Certificate, $loaded->getKind());
        self::assertCount(2, $loaded->references());
        self::assertNull($loaded->getFile());
    }

    public function test_create_with_file_stores_scan(): void
    {
        $issuer = $this->makeIssuer('Лаба-'.bin2hex(random_bytes(3)));
        $id = $this->create($this->createCmd($issuer, [new Reference(ReferenceType::CoatingSystem, Uuid::v7())], file: $this->pdfUpload()));

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertNotNull($loaded->getFile());
        $this->writtenFiles[] = (string) $loaded->getFile();
        self::assertTrue($this->storage->exists((string) $loaded->getFile()));
    }

    public function test_update_replaces_fields_and_references(): void
    {
        $issuer = $this->makeIssuer('Изд-'.bin2hex(random_bytes(3)));
        $id = $this->create($this->createCmd($issuer, [new Reference(ReferenceType::CoatingSystem, Uuid::v7())]));

        $newRef = new Reference(ReferenceType::Coating, Uuid::v7());
        $this->commandBus->execute(new UpdateDocumentCommand(
            $id,
            DocumentKind::Protocol,
            'DOC-2',
            $issuer,
            new \DateTimeImmutable('2024-02-02'),
            new \DateTimeImmutable('2026-02-02'),
            'метанол, пропарка',
            null,
            null,
            [$newRef],
            null,
        ));

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame('DOC-2', $loaded->getTitle());
        self::assertSame(DocumentKind::Protocol, $loaded->getKind());
        self::assertCount(1, $loaded->references());
        self::assertSame(ReferenceType::Coating, $loaded->references()[0]->referenceType);
    }

    public function test_delete_removes_document_and_file(): void
    {
        $issuer = $this->makeIssuer('Дел-'.bin2hex(random_bytes(3)));
        $id = $this->create($this->createCmd($issuer, [new Reference(ReferenceType::CoatingSystem, Uuid::v7())], file: $this->pdfUpload()));

        $this->em->clear();
        $file = (string) $this->repo->findOneById($id)?->getFile();
        self::assertTrue($this->storage->exists($file));

        $this->commandBus->execute(new DeleteDocumentCommand($id));

        self::assertNull($this->repo->findOneById($id));
        self::assertFalse($this->storage->exists($file));
    }

    public function test_get_paged_resolves_issuer_title(): void
    {
        $title = 'Издатель-'.bin2hex(random_bytes(3));
        $issuer = $this->makeIssuer($title);
        $docTitle = 'PAGED-'.bin2hex(random_bytes(3));
        $this->create($this->createCmd($issuer, [new Reference(ReferenceType::CoatingSystem, Uuid::v7())], title: $docTitle));

        $result = $this->queryBus->execute(new GetPagedDocumentsQuery(
            new DocumentsFilter(pager: Pager::fromPage(1, 10), query: $docTitle),
        ));
        \assert($result instanceof GetPagedDocumentsQueryResult);

        self::assertCount(1, $result->documents);
        self::assertSame($title, $result->documents[0]->issuerTitle);
        self::assertCount(1, $result->documents[0]->references);
    }

    public function test_get_document_returns_dto(): void
    {
        $issuer = $this->makeIssuer('Один-'.bin2hex(random_bytes(3)));
        $id = $this->create($this->createCmd($issuer, [new Reference(ReferenceType::CoatingSystem, Uuid::v7())]));

        $result = $this->queryBus->execute(new GetDocumentQuery($id));
        \assert($result instanceof GetDocumentQueryResult);

        self::assertNotNull($result->document);
        self::assertSame($id, $result->document->id);
        self::assertSame('conclusion', $result->document->kind);
        self::assertSame('Заключение', $result->document->kindLabel);
    }
}
