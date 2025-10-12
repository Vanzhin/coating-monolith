<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Tests\Resource\Tools\FixtureTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GetMeActionTest extends WebTestCase
{
    use FixtureTool;
    
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->loadUserFixture();
    }

    public function test_get_me_action(): void
    {
        $client = static::createClient();

        $client->request('POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $this->user->getEmail(),
                'password' => $this->user->getPassword(),
            ])
        );
        $data = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertNotNull($data['data']['token']);

        $client->setServerParameter('HTTP_AUTHORIZATION', sprintf('Bearer %s', $data['data']['token']));

        // act
        $client->request('GET', '/api/users/me');
        // assert
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($this->user->getEmail(), $data['data']['email']);
    }
}
