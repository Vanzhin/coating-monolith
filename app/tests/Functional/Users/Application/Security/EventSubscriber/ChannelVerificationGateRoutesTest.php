<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Application\Security\EventSubscriber;

use App\Users\Application\Security\EventSubscriber\ChannelVerificationGate;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Защита от опечаток в PUBLIC_ROUTES: каждое имя обязано резолвиться через RouterInterface.
 * Если кто-то переименует роут или ошибётся в букве — этот тест упадёт.
 */
final class ChannelVerificationGateRoutesTest extends KernelTestCase
{
    public function testAllPublicRoutesResolveViaRouter(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $reflection = new \ReflectionClassConstant(ChannelVerificationGate::class, 'PUBLIC_ROUTES');
        $routes = $reflection->getValue();

        $missing = [];
        foreach ($routes as $routeName) {
            if ($router->getRouteCollection()->get($routeName) === null) {
                $missing[] = $routeName;
            }
        }

        self::assertSame([], $missing, 'PUBLIC_ROUTES contains route names that do not exist in the router: ' . implode(', ', $missing));
    }
}
