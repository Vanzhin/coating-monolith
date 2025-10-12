<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Tests\Resource\Tools\FixtureTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GetMeActionTest extends WebTestCase
{
    use FixtureTool;

    public function test_get_me_action(): void
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
        
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertNotNull($data['data']['token']);

        $client->setServerParameter('HTTP_AUTHORIZATION', sprintf('Bearer %s', $data['data']['token']));

        // act
        $client->request('GET', '/api/users/me');
        // assert
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($user->getEmail(), $data['data']['email']);
    }
}
