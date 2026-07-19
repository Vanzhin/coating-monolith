<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;

final class RecoatingIntervalTree implements \JsonSerializable
{
    /**
     * Внутренний ассоциативный массив для мгновенного поиска за O(1).
     *
     * @var array<string, RecoatingIntervalTree>
     */
    private array $children = [];

    /**
     * Эталонный нормализованный ключ текущего узла.
     */
    public readonly string $key;

    /**
     * Конструктор с дефолтным ключом и вариативными детьми.
     * Порядок аргументов позволяет создавать плоский корень одной строчкой.
     */
    public function __construct(
        public readonly DryingTimeSeries $default,
        string $key = 'default',
        self ...$children,
    ) {
        $this->key = trim(mb_strtolower($key, 'UTF-8'));

        foreach ($children as $child) {
            // Ключом в массиве становится уже нормализованный ключ ребенка
            $this->children[$child->key] = $child;
        }
    }

    /**
     * Возвращает новое дерево с добавленным/заменённым дочерним узлом.
     */
    public function withChild(self $child): self
    {
        $children = $this->children;
        $children[$child->key] = $child;

        return new self($this->default, $this->key, ...array_values($children));
    }

    /**
     * Возвращает новое дерево без указанного дочернего узла.
     */
    public function withoutChild(string $key): self
    {
        $normalizedKey = trim(mb_strtolower($key, 'UTF-8'));
        $children = $this->children;
        unset($children[$normalizedKey]);

        return new self($this->default, $this->key, ...array_values($children));
    }

    /**
     * Умный поиск значения по цепочке ключей с автоматическим fallback.
     * Если путь обрывается, возвращает дефолт последней успешно найденной папки.
     */
    public function find(string ...$keys): RecoatingSearchResult
    {
        $currentNode = $this;
        $matchedPath = [];

        foreach ($keys as $key) {
            $normalizedKey = trim(mb_strtolower($key, 'UTF-8'));

            if (!isset($currentNode->children[$normalizedKey])) {
                // Путь оборвался: отдаем накопленный чистый путь и дефолт текущей ноды
                return new RecoatingSearchResult($currentNode->default, false, new StringCollection(...$matchedPath));
            }

            $matchedPath[] = $normalizedKey;
            $currentNode = $currentNode->children[$normalizedKey];
        }

        // Успешно прошли всю цепочку ключей без сбоев
        return new RecoatingSearchResult($currentNode->default, true, new StringCollection(...$matchedPath));
    }

    /**
     * Находит сам объект узла по цепочке ключей (необходим, чтобы найти родителя).
     */
    public function findNode(string ...$keys): ?self
    {
        $currentNode = $this;
        foreach ($keys as $key) {
            $normalizedKey = trim(mb_strtolower($key, 'UTF-8'));
            if (!isset($currentNode->children[$normalizedKey])) {
                return null;
            }
            $currentNode = $currentNode->children[$normalizedKey];
        }

        return $currentNode;
    }

    /**
     * Геттер для получения карты детей (используется валидатором в Coating).
     *
     * @return array<string, RecoatingIntervalTree>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Формат хранения в jsonb: `{default, children: {<key>: <node>, ...}}`.
     * Ключ узла авторитетно живёт во внешнем ассоциативном ключе родительского children-словаря;
     * для корня — берётся 'default' при чтении.
     */
    public function jsonSerialize(): array
    {
        return [
            'default' => $this->default,
            'children' => (object) $this->children, // (object) гарантирует {}, если детей нет
        ];
    }

    /**
     * Фабрика для сборки дерева из сырого JSON/массива.
     *
     * @param string $key — ключ текущего узла (для корня — 'default'). Если в $raw присутствует
     *                    устаревшее поле 'key' (легаси-данные), оно игнорируется.
     */
    public static function fromArray(array $raw, string $key = 'default'): self
    {
        $rawDefault = $raw['default'] ?? null;
        if (!is_array($rawDefault) || [] === $rawDefault) {
            throw new AppException(sprintf('Дерево интервалов перекрытия повреждено: default-серия пуста или отсутствует у узла "%s".', $key));
        }

        $children = [];
        foreach ((array) ($raw['children'] ?? []) as $outerKey => $childRaw) {
            $children[] = self::fromArray((array) $childRaw, (string) $outerKey);
        }

        return new self(DryingTimeSeries::fromArray($rawDefault), $key, ...$children);
    }
}
