<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Security\LoginFormAuthenticator;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Application\UseCase\Command\CreateUser\CreateUserCommand;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Service\Validation\EmailValidatorInterface;
use App\Users\Infrastructure\Form\RegistrationFormType;
use App\Users\Infrastructure\Security\RegistrationBotGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class RegistrationController extends AbstractController
{
    // Один нейтральный ответ на любой анти-бот триггер (honeypot / time-trap / rate-limit) —
    // чтобы по тексту нельзя было понять, какой именно признак вызвал отказ.
    private const AUTOMATED_SUBMISSION_MESSAGE = 'Недействительные регистрационные данные.';

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly UserRepositoryInterface $userRepository,
        private readonly UserAuthenticatorInterface $userAuthenticator,
        private readonly LoginFormAuthenticator $authenticator,
        private readonly EmailValidatorInterface $emailListValidator,
        private readonly RegistrationBotGuard $botGuard,
        #[Autowire(service: 'limiter.registration_per_ip')]
        private readonly RateLimiterFactory $registrationPerIpLimiter,
    ) {
    }

    #[Route('/sign-up', name: 'app_sign_up')]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        // Rate-limit по IP: тормозим массовый/ботский флуд регистраций с одного адреса.
        // Считаем любую отправку; при исчерпании — нейтральный ответ.
        if ($form->isSubmitted()
            && !$this->registrationPerIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->renderRegisterFailure($form, self::AUTOMATED_SUBMISSION_MESSAGE);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Анти-бот: honeypot заполнен / форма отправлена слишком быстро / метка подделана.
            if ($this->botGuard->looksAutomated($form->get('website')->getData(), $form->get('ts')->getData())) {
                return $this->renderRegisterFailure($form, self::AUTOMATED_SUBMISSION_MESSAGE);
            }

            $password = $form->get('plainPassword')->getData();
            $email = $form->get('email')->getData();
            // посторонних не пускаем
            if (!$this->emailListValidator->isValid($email)) {
                return $this->renderRegisterFailure($form, sprintf('С почтой `%s` зарегистрироваться невозможно.', $email));
            }

            try {
                $result = $this->commandBus->execute(new CreateUserCommand($email, $password));
            } catch (AppException $e) {
                return $this->renderRegisterFailure($form, $e->getMessage());
            }

            $user = $this->userRepository->getByUlid((string) $result->ulid);

            $this->addFlash('register_success', 'Регистрация прошла успешно.');

            return $this->userAuthenticator->authenticateUser(
                $user,
                $this->authenticator,
                $request
            );
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    private function renderRegisterFailure(FormInterface $form, string $message): Response
    {
        $this->addFlash('register_failure', $message);

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}
