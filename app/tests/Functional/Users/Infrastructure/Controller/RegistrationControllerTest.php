<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * Анти-бот на регистрации: honeypot / time-trap / rate-limit. На любой триггер — нейтральный
 * ответ (юзер не создаётся, причина не раскрывается). Человек (пустой honeypot + метка старше
 * порога) регистрируется штатно.
 */
final class RegistrationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // Rate-limiter живёт в Redis (DAMA его не откатывает) — сбрасываем для тестового IP,
        // чтобы методы не влияли друг на друга.
        static::getContainer()->get('limiter.registration_per_ip')->create('127.0.0.1')->reset();
    }

    public function test_filled_honeypot_is_rejected_and_creates_no_user(): void
    {
        $email = 'hp_'.bin2hex(random_bytes(4)).'@example.com';

        $this->submit($email, static function (Form $form): void {
            $form['registration_form[website]'] = 'http://spam.example';
        });

        self::assertResponseIsSuccessful(); // ре-рендер формы, не редирект
        self::assertFalse($this->userExists($email), 'бот с заполненным honeypot не должен создать юзера');
        self::assertStringContainsString('Недействительные регистрационные данные', (string) $this->client->getResponse()->getContent());
    }

    public function test_too_fast_submission_is_rejected(): void
    {
        // ts не трогаем → свежая метка (возраст ~0) меньше порога 3с.
        $email = 'fast_'.bin2hex(random_bytes(4)).'@example.com';

        $this->submit($email, static function (Form $form): void {});

        self::assertResponseIsSuccessful();
        self::assertFalse($this->userExists($email), 'слишком быстрый сабмит не должен создать юзера');
        self::assertStringContainsString('Недействительные регистрационные данные', (string) $this->client->getResponse()->getContent());
    }

    public function test_human_registration_succeeds(): void
    {
        $secret = (string) static::getContainer()->getParameter('kernel.secret');
        $ts = (string) (time() - 10);
        $agedToken = $ts.'.'.hash_hmac('sha256', $ts, $secret);
        $email = 'human_'.bin2hex(random_bytes(4)).'@example.com';

        $this->submit($email, static function (Form $form) use ($agedToken): void {
            $form['registration_form[ts]'] = $agedToken; // метка старше порога → не бот
            // honeypot оставляем пустым
        });

        self::assertResponseRedirects(); // authenticateUser → редирект после успеха
        self::assertTrue($this->userExists($email), 'валидная регистрация человеком должна создать юзера');
    }

    private function submit(string $email, callable $tweak): void
    {
        $crawler = $this->client->request('GET', '/sign-up');
        $form = $crawler->selectButton('Зарегистрироваться')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'Password1',
        ]);
        $tweak($form);
        $this->client->submit($form);
    }

    private function userExists(string $email): bool
    {
        return null !== $this->em->getRepository(User::class)->findOneBy(['email.value' => $email]);
    }
}
