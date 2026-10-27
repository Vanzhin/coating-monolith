<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Вырезает секреты из того, что уходит в аудит: чувствительные заголовки целиком и
 * значения «секретных» query-параметров. Телеметрия не должна хранить креды (ключи,
 * токены, cookie). Чистая строковая операция, без доменного смысла.
 */
final class HttpHeaderRedactor
{
    public const REDACTED = '[REDACTED]';

    /** Заголовки, значение которых никогда не храним (сравнение в нижнем регистре). */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'x-csrf-token',
    ];

    /** Подстроки в имени query-параметра, выдающие секрет (нижний регистр). */
    private const SENSITIVE_QUERY_NEEDLES = [
        'key',
        'token',
        'secret',
        'password',
        'passwd',
        'sig',
        'signature',
    ];

    /**
     * @param array<string, list<string>|string> $headers
     *
     * @return array<string, list<string>|string>
     */
    public function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[$name] = \in_array(strtolower($name), self::SENSITIVE_HEADERS, true)
                ? self::REDACTED
                : $value;
        }

        return $out;
    }

    public function redactQuery(?string $query): ?string
    {
        if (null === $query || '' === $query) {
            return $query;
        }

        parse_str($query, $params);

        return http_build_query($this->redactParams($params));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function redactParams(array $params): array
    {
        foreach ($params as $name => $value) {
            if (\is_array($value)) {
                $params[$name] = $this->redactParams($value);
                continue;
            }
            if ($this->isSensitive((string) $name)) {
                $params[$name] = self::REDACTED;
            }
        }

        return $params;
    }

    private function isSensitive(string $paramName): bool
    {
        $lower = strtolower($paramName);
        foreach (self::SENSITIVE_QUERY_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
