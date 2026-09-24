<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Form;

use App\Users\Infrastructure\Security\FormTimestampSigner;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function __construct(
        private readonly FormTimestampSigner $timestampSigner,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(message: 'Please enter a password'),
                    new Length(min: 8, minMessage: 'Пароль должен содержать как минимум 8 символов'),
                    new Regex(pattern: '/[A-ZА-Я]/u', message: 'Пароль должен содержать хотя бы одну заглавную букву'),
                    new Regex(pattern: '/[0-9]/', message: 'Пароль должен содержать хотя бы одну цифру'),
                ],
            ])
            // Анти-бот (решение принимает RegistrationBotGuard на бэке; фронт только рисует).
            // honeypot: поле-ловушка — человек его не видит (спрятано в шаблоне), бот заполняет.
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
            ])
            // time-trap: подписанная метка времени рендера; сабмит быстрее порога = бот.
            ->add('ts', HiddenType::class, [
                'mapped' => false,
                'data' => $this->timestampSigner->mint(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
    }
}
