<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GetUserActionTest extends WebTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Создаём пользователя программно
        $this->user = new User(new Email('test@example.com'));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user->setPassword('password123', $hasher);
        
        // Сохраняем в базу
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->persist($this->user);
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Очищаем базу после теста
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->remove($this->user);
        $entityManager->flush();
        
        parent::tearDown();
    }

    public function test_get_user_action(): void
    {
        $client = static::createClient();

        $client->request('POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $this->user->getEmail(),
                'password' => 'password123',
            ])
        );
        $data = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertNotNull($data['data']['token']);

        $client->setServerParameter('HTTP_AUTHORIZATION', sprintf('Bearer %s', $data['data']['token']));

        // act
        $client->request('GET', '/api/users/'.$this->user->getUlid());
        // assert
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($this->user->getEmail(), $data['data']['email']);
        $this->assertEquals($this->user->getUlid(), $data['data']['id']);
    }
}
