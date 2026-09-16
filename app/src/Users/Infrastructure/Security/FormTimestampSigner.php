<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Подписанная метка времени рендера формы (time-trap против ботов). mint() кладём в скрытое поле
 * при отрисовке, secondsSince() на сабмите проверяет подпись и возвращает, сколько прошло.
 * Подпись HMAC секретом приложения → метку нельзя подделать или подставить чужое время;
 * состояние нигде не хранится (stateless, безопасно для нескольких вкладок).
 */
final readonly class FormTimestampSigner
{
    public function __construct(
        #[Autowire('%kernel.secret%')] private string $secret,
    ) {
    }

    public function mint(): string
    {
        $ts = (string) time();

        return $ts.'.'.$this->sign($ts);
    }

    /** Сколько секунд прошло с момента mint(), либо null если метка отсутствует/битая/подделана. */
    public function secondsSince(?string $token): ?int
    {
        if (null === $token || !str_contains($token, '.')) {
            return null;
        }

        [$ts, $signature] = explode('.', $token, 2);
        if (!ctype_digit($ts) || !hash_equals($this->sign($ts), $signature)) {
            return null;
        }

        return time() - (int) $ts;
    }

    private function sign(string $ts): string
    {
        return hash_hmac('sha256', $ts, $this->secret);
    }
}
