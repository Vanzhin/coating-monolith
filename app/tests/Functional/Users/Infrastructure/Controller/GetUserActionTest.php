<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Tests\Resource\Tools\FixtureTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GetUserActionTest extends WebTestCase
{
    use FixtureTool;

    public function test_get_user_action(): void
    {
        $client = static::createClient();
        $user = $this->loadUserFixture();

        $client->request('POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $user->getEmail(),
                'password' => $user->getPassword(),
            ])
        );
        $data = json_decode($client->getResponse()->getContent(), true);

        $client->setServerParameter('HTTP_AUTHORIZATION', sprintf('Bearer %s', $data['data']['token']));

        // act
        $client->request('GET', '/api/users/'.$user->getUlid());
        // assert
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($user->getEmail(), $data['data']['email']);
        $this->assertEquals($user->getUlid(), $data['data']['id']);
    }
}
