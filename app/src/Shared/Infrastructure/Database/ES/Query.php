<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\ES;

class Query implements \JsonSerializable
{
    private array $query = ['bool' => []];
    private int $from = 0;
    private int $size = 10;
    private array $sort = [];
    private int $minimumShouldMatch = 0;
    private array $aggregations = [];

    public function setMinimumShouldMatch(int $value): void
    {
        $this->minimumShouldMatch = $value;
    }

    public function addMust(array $query): void
    {
        $this->query['bool']['must'][] = $query;
    }

    public function addShould(array $query): void
    {
        $this->query['bool']['should'][] = $query;
    }

    public function addFilter(array $query): void
    {
        $this->query['bool']['filter'][] = $query;
    }

    public function addMustNot(array $query): void
    {
        $this->query['bool']['must_not'][] = $query;
    }

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

    public function addAggregation(string $name, array $body): void
    {
        $this->aggregations[$name] = $body;
    }

    public function getQuery(): array
    {
        return $this->jsonSerialize();
    }

    public function jsonSerialize(): array
    {
        $result = ['query' => $this->query];
        if (!empty($this->aggregations)) {
            $result['aggs'] = $this->aggregations;
        }

        if ($this->minimumShouldMatch !== 0) {
            $result['query']['bool']['minimum_should_match'] = $this->minimumShouldMatch;
        }

        if ($this->from > 0) {
            $result['from'] = $this->from;
        }

        if ($this->size !== 10) {
            $result['size'] = $this->size;
        }

        if (!empty($this->sort)) {
            $result['sort'] = $this->sort;
        }

        return $result;
    }
}