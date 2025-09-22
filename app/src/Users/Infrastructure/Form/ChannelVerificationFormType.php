<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Form;

use App\Users\Domain\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ChannelVerificationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['user'];
        $channels = $user->getChannels()->toArray();
        $unverifiedChannels = array_filter($channels, function ($channel) {
            return !$channel->isVerified();
        });

        $builder
            ->add('channel', EntityType::class, [
                'label' => 'Канал для верификации',
                'class' => 'App\Users\Domain\Entity\Channel',
                'choices' => $unverifiedChannels,
                'choice_label' => function ($channel) {
                    return sprintf('%s (%s)', $channel->getType()->value, $channel->getValue());
                },
                'choice_attr' => function ($channel) {
                    return [
                        'data-channel-type' => $channel->getType()->value,
                        'data-channel-value' => $channel->getValue()
                    ];
                },
                'placeholder' => 'Выберите канал для верификации',
                'attr' => [
                    'class' => 'form-select',
                    'id' => 'verification_channel'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Пожалуйста, выберите канал для верификации',
                    ]),
                ],
            ])
            ->add('token', TextType::class, [
                'label' => 'Код верификации',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Введите 6-значный код',
                    'maxlength' => 6,
                    'autocomplete' => 'one-time-code',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Пожалуйста, введите код верификации',
                    ]),
                    new Length([
                        'min' => 6,
                        'max' => 6,
                        'exactMessage' => 'Код должен содержать ровно 6 цифр',
                    ]),
                    new Regex([
                        'pattern' => '/^[0-9]{6}$/',
                        'message' => 'Код должен содержать только цифры',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'user' => null,
        ]);

        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
