<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Form;

use App\Users\Domain\Entity\ChannelType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class CreateChannelFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Тип канала',
                'choices' => [
                    'Email' => ChannelType::EMAIL->value,
                    'Telegram' => ChannelType::TELEGRAM->value,
                ],
                'placeholder' => 'Выберите тип канала',
                'attr' => [
                    'class' => 'form-select',
                    'id' => 'channel_type',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Пожалуйста, выберите тип канала',
                    ]),
                ],
            ])
            ->add('value', TextType::class, [
                'label' => 'Значение',
                'attr' => [
                    'class' => 'form-control',
                    'id' => 'channel_value',
                    'placeholder' => 'Введите значение канала',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Пожалуйста, введите значение канала',
                    ]),
                    new Length([
                        'min' => 3,
                        'max' => 255,
                        'minMessage' => 'Значение должно содержать минимум {{ limit }} символов',
                        'maxMessage' => 'Значение должно содержать максимум {{ limit }} символов',
                    ]),
                ],
            ]);

        // Настройка поля value при начальной загрузке формы с данными (например, из query параметров)
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();

            if (!isset($data['type']) || empty($data['type'])) {
                return;
            }

            $type = $data['type'];

            // Удаляем старое поле value
            $form->remove('value');

            // Добавляем новое поле value с валидацией в зависимости от типа
            if ($type === ChannelType::EMAIL->value) {
                $form->add('value', TextType::class, [
                    'label' => 'Email',
                    'data' => $data['value'] ?? null,
                    'attr' => [
                        'class' => 'form-control',
                        'id' => 'channel_value',
                        'placeholder' => 'example@domain.com',
                        'type' => 'email',
                    ],
                    'constraints' => [
                        new NotBlank([
                            'message' => 'Пожалуйста, введите email',
                        ]),
                        new Email([
                            'message' => 'Пожалуйста, введите корректный email адрес',
                            'mode' => 'strict',
                        ]),
                    ],
                ]);
            } else {
                // Telegram: принимаем username (с @ или без) или user_id (только цифры)
                $form->add('value', TextType::class, [
                    'label' => 'Telegram',
                    'data' => $data['value'] ?? null,
                    'attr' => [
                        'class' => 'form-control',
                        'id' => 'channel_value',
                        'placeholder' => '@username или user_id',
                    ],
                    'constraints' => [
                        new NotBlank([
                            'message' => 'Пожалуйста, введите Telegram username или user_id',
                        ]),
                        new Length([
                            'min' => 3,
                            'max' => 255,
                            'minMessage' => 'Значение должно содержать минимум {{ limit }} символов',
                            'maxMessage' => 'Значение должно содержать максимум {{ limit }} символов',
                        ]),
                    ],
                ]);
            }
        });

        // Динамическая валидация в зависимости от типа канала при отправке формы
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();

            if (!isset($data['type'])) {
                return;
            }

            $type = $data['type'];
            $value = $data['value'] ?? null;

            // Удаляем старое поле value
            $form->remove('value');

            // Добавляем новое поле value с валидацией в зависимости от типа
            if ($type === ChannelType::EMAIL->value) {
                $form->add('value', TextType::class, [
                    'label' => 'Email',
                    'attr' => [
                        'class' => 'form-control',
                        'id' => 'channel_value',
                        'placeholder' => 'example@domain.com',
                        'type' => 'email',
                    ],
                    'constraints' => [
                        new NotBlank([
                            'message' => 'Пожалуйста, введите email',
                        ]),
                        new Email([
                            'message' => 'Пожалуйста, введите корректный email адрес',
                            'mode' => 'strict',
                        ]),
                    ],
                ]);
            } else {
                // Telegram: принимаем username (с @ или без) или user_id (только цифры)
                $form->add('value', TextType::class, [
                    'label' => 'Telegram',
                    'attr' => [
                        'class' => 'form-control',
                        'id' => 'channel_value',
                        'placeholder' => '@username или user_id',
                    ],
                    'constraints' => [
                        new NotBlank([
                            'message' => 'Пожалуйста, введите Telegram username или user_id',
                        ]),
                        new Length([
                            'min' => 3,
                            'max' => 255,
                            'minMessage' => 'Значение должно содержать минимум {{ limit }} символов',
                            'maxMessage' => 'Значение должно содержать максимум {{ limit }} символов',
                        ]),
                    ],
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}

