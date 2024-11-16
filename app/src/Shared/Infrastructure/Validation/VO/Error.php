<?php

namespace App\Shared\Infrastructure\Validation\VO;

readonly class Error implements \JsonSerializable
{
    public function __construct(private string $property, private string $message)
    {
    }

    /**
     * @return string
     */
    public function getProperty(): string
    {
        return $this->property;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    public function getFullMessage(): string
    {
        return $this->property . ' - ' . $this->message;
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}