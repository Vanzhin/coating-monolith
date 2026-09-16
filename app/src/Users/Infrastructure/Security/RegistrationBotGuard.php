<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Security;

/**
 * Бэковый детект автоматической регистрации — вся логика решения здесь, фронт лишь рисует поля.
 * Считаем сабмит ботским, если: honeypot-поле заполнено (человек его не видит), либо метка времени
 * формы отсутствует/подделана, либо форма отправлена подозрительно быстро (< MIN_FORM_AGE_SECONDS).
 * Причину наружу не отдаём — контроллер на любой true отвечает одним нейтральным сообщением,
 * чтобы бот не понял, какой именно признак его выдал.
 */
final readonly class RegistrationBotGuard
{
    private const MIN_FORM_AGE_SECONDS = 3;

    public function __construct(
        private FormTimestampSigner $timestampSigner,
    ) {
    }

    public function looksAutomated(?string $honeypotValue, ?string $timestampToken): bool
    {
        if (null !== $honeypotValue && '' !== trim($honeypotValue)) {
            return true;
        }

        $ageSeconds = $this->timestampSigner->secondsSince($timestampToken);

        return null === $ageSeconds || $ageSeconds < self::MIN_FORM_AGE_SECONDS;
    }
}
