<?php

declare(strict_types=1);

namespace App\Tests\Functional\Documents\Application;

use App\Documents\Application\UseCase\Command\BulkInsertDocument\BulkInsertDocumentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Гейт массовой вставки документов: только управляющий (админ/система). Раньше авторизации не было
 * вовсе — любой JWT-холдер писал в ES-индекс. Проверяем, что обычный юзер получает Forbidden ДО
 * обращения к файлу/ES (гейт срабатывает первым, поэтому путь файла может быть любым).
 */
final class BulkInsertDocumentAuthorizationTest extends KernelTestCase
{
    public function test_regular_user_cannot_bulk_insert(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(new Email('doc_'.bin2hex(random_bytes(4)).'@example.com'));
        $user->setPassword('pass', static::getContainer()->get(UserPasswordHasherInterface::class));
        $em->persist($user);
        $em->flush();

        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new PreAuthenticatedToken($user, 'test', $user->getRoles())
        );

        $this->expectException(ForbiddenException::class);
        static::getContainer()->get(CommandBusInterface::class)
            ->execute(new BulkInsertDocumentCommand('/nonexistent', null));
    }
}
