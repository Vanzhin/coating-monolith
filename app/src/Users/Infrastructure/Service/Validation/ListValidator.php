<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Service\Validation;

use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Service\Validation\ValidatorInterface;
use Stringable;

abstract class ListValidator implements ValidatorInterface
{
    public function __construct(
        protected array $whitelist = [],
        protected array $blacklist = [],
        protected array $whitelistPatterns = [],
        protected array $blacklistPatterns = [],
        protected bool $whitelistEnabled = true,
        protected bool $blacklistEnabled = true
    ) {}

    public function isValid(string|Stringable $value): bool
    {
        try {
            $normalizedValue = $this->normalizeValue($value);

            // Проверка черного списка
            if ($this->blacklistEnabled && $this->isInBlacklist($normalizedValue)) {
                return false;
            }

            // Проверка белого списка
            if ($this->whitelistEnabled && !$this->isInWhitelist($normalizedValue)) {
                return false;
            }

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function supports(string|Stringable $value): bool
    {
        try {
            $normalizedValue = $this->normalizeValue($value);
            return $this->isSupportedNormalized($normalizedValue);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    protected function validate(string|Stringable $value): void
    {
        if (!$this->isValid($value)) {
            throw new AppException($this->getValidationErrorMessage($value));
        }
    }

    protected function isInBlacklist(string $normalizedValue): bool
    {
        // Проверка точного совпадения
        if (in_array($normalizedValue, $this->blacklist, true)) {
            return true;
        }

        // Проверка по паттернам
        foreach ($this->blacklistPatterns as $pattern) {
            if (fnmatch($pattern, $normalizedValue)) {
                return true;
            }
        }

        return false;
    }

    protected function isInWhitelist(string $normalizedValue): bool
    {
        // Если белый список пуст - разрешаем все
        if (empty($this->whitelist) && empty($this->whitelistPatterns)) {
            return true;
        }

        // Проверка точного совпадения
        if (in_array($normalizedValue, $this->whitelist, true)) {
            return true;
        }

        // Проверка по паттернам
        foreach ($this->whitelistPatterns as $pattern) {
            if (fnmatch($pattern, $normalizedValue)) {
                return true;
            }
        }

        return false;
    }

    protected function isSupportedNormalized(string $normalizedValue): bool
    {
        return true;
    }

    protected function normalizeValue(string|Stringable $value): string
    {
        return (string)$value;
    }

    protected function getValidationErrorMessage(string|Stringable $value): string
    {
        $stringValue = (string)$value;

        if ($this->blacklistEnabled && $this->isInBlacklist((string)$value)) {
            return sprintf('Value "%s" is in blacklist', $stringValue);
        }

        if ($this->whitelistEnabled && !$this->isInWhitelist((string)$value)) {
            return sprintf('Value "%s" is not in whitelist', $stringValue);
        }

        return sprintf('Value "%s" validation failed', $stringValue);
    }

    /**
     * Вспомогательные методы для работы с паттернами
     */
    protected function convertToPattern(string $value): string
    {
        // Преобразует значение в паттерн fnmatch
        return str_replace(['*', '?'], ['*', '?'], $value);
    }

    protected function validatePattern(string $pattern): bool
    {
        return !empty($pattern) && is_string($pattern);
    }
}