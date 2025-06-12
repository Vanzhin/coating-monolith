<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\ES;

class Query implements \JsonSerializable
{
    private array $must = [];
    private array $filter = [];
    private array $should = [];
    private array $must_not = [];
    private int $size = 10;
    private int $from = 0;


    public function addFilter(array $data): void
    {
        $this->filter[] = $data;
    }

    public function addMust(array $data): void
    {
        $this->must[] = $data;
    }

    public function addShould(array $data): void
    {
        $this->should[] = $data;
    }

    public function addMustNot(array $data): void
    {
        $this->must_not[] = $data;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function getFrom(): int
    {
        return $this->from;
    }

    public function setFrom(int $from): void
    {
        $this->from = $from;
    }

    public function jsonSerialize(): string
    {
        $size = $this->getSize();
        $from = $this->getFrom();
        $result = compact('size', 'from');
        if ($this->must) {
            $result['query']['bool']['must'] = $this->must;
        }
        if ($this->filter) {
            $result['query']['bool']['filter'] = $this->filter;
        }
        if ($this->should) {
            $result['query']['bool']['should'] = $this->should;
            $result['query']['bool']['minimum_should_match'] = 1;
        }
        if ($this->must_not) {
            $result['query']['bool']['must_not'] = $this->must_not;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}