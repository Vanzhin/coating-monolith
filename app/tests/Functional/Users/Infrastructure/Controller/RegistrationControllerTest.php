<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Анти-энумерация на регистрации: занятый и свободный email дают одинаковый нейтральный ответ,
 * без «почта уже существует» и без авто-логина (иначе новый юзер отличался бы редиректом).
 */
final class RegistrationControllerTest extends WebTestCase
{
    public function test_existing_and_new_email_yield_identical_neutral_response(): void
    {
        $client = static::createClient();
        $c = $client->getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $existingEmail = 'dup_'.uniqid('', true).'@example.com';
        $user = new User(new Email($existingEmail));
        $user->setPassword('Password1', $hasher);
        $em->persist($user);
        $em->flush();

        $newContent = $this->submitRegistration($client, 'new_'.uniqid('', true).'@example.com');
        $dupContent = $this->submitRegistration($client, $existingEmail);

        // Оракула нет: занятый адрес не выдаёт «уже существует».
        self::assertStringNotContainsString('уже существует', $dupContent);
        // Оба — один нейтральный экран.
        self::assertStringContainsString('Регистрация обработана', $newContent);
        self::assertStringContainsString('Регистрация обработана', $dupContent);
    }

    private function submitRegistration(KernelBrowser $client, string $email): string
    {
        $crawler = $client->request('GET', '/sign-up');
        $form = $crawler->filter('form')->form();
        $form['registration_form[email]'] = $email;
        $form['registration_form[plainPassword]'] = 'Password1';
        $client->submit($form);

        // Нейтрально: перерисовка формы (200), а не редирект в кабинет — авто-логина нет.
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }
}
