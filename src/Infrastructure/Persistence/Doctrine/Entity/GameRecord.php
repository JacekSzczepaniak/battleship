<?php

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ORM\Table(name: 'games')]
class GameRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $id;

    #[ORM\Column(type: Types::JSON, nullable: false)]
    private array $state = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_MUTABLE)]
    private \DateTime $updatedAt;

    public function __construct(string $id, array $state)
    {
        $this->id = $id;
        $this->state = $state;
        $this->createdAt = new \DateTimeImmutable('now');
        $this->updatedAt = new \DateTime('now');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function state(): array
    {
        return $this->state;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function setState(array $state): void
    {
        $this->state = $state;
        $this->version++;
        $this->updatedAt = new \DateTime('now');
    }
}
