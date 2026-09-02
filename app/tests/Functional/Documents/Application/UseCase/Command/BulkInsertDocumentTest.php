<?php

declare(strict_types=1);

namespace App\Tests\Functional\Documents\Application\UseCase\Command;

use App\Documents\Application\Service\AccessControl\DocumentAccessControl;
use App\Documents\Application\UseCase\Command\BulkInsertDocument\BulkInsertDocumentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\AuthenticatesUserTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Регресс: /api/document/bulk-add раньше не имел авторизации — любой JWT-юзер писал в ES.
 * Теперь массовая запись гейтится DocumentAccessControl::canManage (админ/система),
 * а целевой индекс больше не принимается от клиента.
 */
final class BulkInsertDocumentTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use AuthenticatesUserTrait;

    private CommandBusInterface $commandBus;
    private DocumentAccessControl $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->access = $c->get(DocumentAccessControl::class);
    }

    public function test_manager_can_manage(): void
    {
        $this->authenticateAsSystem();

        self::assertTrue($this->access->canManage());
    }

    public function test_non_manager_cannot_manage(): void
    {
        $this->authenticateAsUser('01USER00000000000000000000');

        self::assertFalse($this->access->canManage());
    }

    public function test_bulk_insert_forbidden_for_non_manager(): void
    {
        $this->authenticateAsUser('01USER00000000000000000000');

        // Гейт срабатывает до чтения файла и любого обращения к Elasticsearch,
        // поэтому несуществующий путь до сита не доходит.
        $this->expectException(ForbiddenException::class);
        $this->commandBus->execute(new BulkInsertDocumentCommand('/nonexistent/does-not-matter'));
    }
}
