<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller\Channel;

use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rate-limit перебора OTP верификации канала (5/15мин на юзера): после 5 попыток ввода кода вход
 * упирается в лимит. Ключ лимитера — id юзера (уникальный на тест → изоляция; Redis не откатывается
 * DAMA). Реальный OTP-токен не нужен: лимитер консюмится до verify-команды. Email-канал заводит
 * авто-хендлер UserCreatedEvent — сами не создаём (иначе дубль).
 */
final class ChannelVerificationThrottlingTest extends WebTestCase
{
    public function test_repeated_code_attempts_get_throttled(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $email = 'otp_'.bin2hex(random_bytes(4)).'@example.com';
        $user = new User(new Email($email));
        $user->setPassword('pass', static::getContainer()->get(UserPasswordHasherInterface::class));
        $em->persist($user);
        $em->flush();
        // Email-канал создаёт авто-хендлер UserCreatedEvent. Перечитываем юзера из БД, чтобы у
        // залогиненного инстанса коллекция channels была свежей (иначе форма не увидит канал).
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email.value' => $email]);
        self::assertNotNull($user);

        $channel = static::getContainer()->get(ChannelRepositoryInterface::class)
            ->findOneByOwnerTypeValue($user->getId(), ChannelType::EMAIL->value, $email);
        self::assertNotNull($channel, 'Авто-хендлер должен был создать email-канал.');

        $client->loginUser($user);

        for ($i = 1; $i <= 6; ++$i) {
            $crawler = $client->request('GET', '/user/channel/verification');
            $form = $crawler->filter('form[action*="channel/verification"]')->form();
            $form['channel_verification_form[channel]'] = $channel->getId();
            $form['channel_verification_form[token]'] = '000000';
            $client->submit($form);
        }

        self::assertStringContainsString(
            'Слишком много попыток',
            (string) $client->getResponse()->getContent(),
            'После 5 попыток ввода кода верификация должна упираться в rate-limit.',
        );
    }
}
