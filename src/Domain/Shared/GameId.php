<?php

namespace App\Domain\Shared;

use Symfony\Component\Uid\Uuid;

final class GameId
{
    public function __construct(private string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Invalid UUID');
        }
    }

    public static function new(): self
    {
        return new self(Uuid::v4()->toRfc4122());
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
