<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Application\Security\EventSubscriber;

use App\Users\Application\Security\EventSubscriber\ChannelVerificationGate;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class ChannelVerificationGateTest extends TestCase
{
    private const VERIFICATION_URL = '/user/channel/verification';

    public function test_skips_when_sub_request(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list', HttpKernelInterface::SUB_REQUEST);
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController()); // controller не подменён
    }

    public function test_skips_when_public_route(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_login');
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function test_skips_when_route_null(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent(null);
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function test_skips_when_anonymous(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list');
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function test_skips_when_user_active(): void
    {
        $user = new User(new Email('active@example.com'));
        $this->forceIsActive($user, true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list');
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function test_redirects_when_user_inactive(): void
    {
        $user = new User(new Email('inactive@example.com'));
        // isActive по умолчанию false — менять ничего не нужно.

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list');
        $gate->onController($event);

        $controller = $event->getController();
        self::assertIsCallable($controller);
        /** @var Response $response */
        $response = $controller();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::VERIFICATION_URL, $response->getTargetUrl());
    }

    // --- helpers ---

    public function noopController(): Response
    {
        return new Response('noop');
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('app_user_channel_verification')
            ->willReturn(self::VERIFICATION_URL);

        return $urlGenerator;
    }

    private function controllerEvent(?string $route, int $requestType = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        $request = new Request();
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ControllerEvent($kernel, [$this, 'noopController'], $request, $requestType);
    }

    private function forceIsActive(User $user, bool $value): void
    {
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, $value);
    }
}
