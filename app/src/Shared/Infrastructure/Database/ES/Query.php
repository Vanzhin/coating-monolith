<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\ES;

class Query implements \JsonSerializable
{
    /** @var array<string, mixed> */
    private array $query = ['bool' => []];
    private int $from = 0;
    private int $size = 10;
    /** @var list<array<string, mixed>> */
    private array $sort = [];
    private int $minimumShouldMatch = 0;
    /** @var array<string, mixed> */
    private array $aggregations = [];

    public function setMinimumShouldMatch(int $value): void
    {
        $this->minimumShouldMatch = $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function addMust(array $query): void
    {
        $this->query['bool']['must'][] = $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function addShould(array $query): void
    {
        $this->query['bool']['should'][] = $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function addFilter(array $query): void
    {
        $this->query['bool']['filter'][] = $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function addMustNot(array $query): void
    {
        $this->query['bool']['must_not'][] = $query;
    }

    /**
     * @param array<string, mixed> $sort
     */
    public function addSort(array $sort): void
    {
        $this->sort[] = $sort;
    }

    public function setFrom(int $from): void
    {
        $this->from = $from;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function addAggregation(string $name, array $body): void
    {
        $this->aggregations[$name] = $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuery(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $result = ['query' => $this->query];
        if (!empty($this->aggregations)) {
            $result['aggs'] = $this->aggregations;
        }

        if (0 !== $this->minimumShouldMatch) {
            $result['query']['bool']['minimum_should_match'] = $this->minimumShouldMatch;
        }

        if ($this->from > 0) {
            $result['from'] = $this->from;
        }

        if (10 !== $this->size) {
            $result['size'] = $this->size;
        }

        if (!empty($this->sort)) {
            $result['sort'] = $this->sort;
        }

        return $result;
    }
}
