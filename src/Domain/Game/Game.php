<?php

namespace App\Domain\Game;

use App\Domain\Shared\GameId;

final class Game
{
    private GameStatus $status = GameStatus::Pending;

    /** @var Ship[]|null */
    private ?array $fleet = null;

    public function __construct(
        private GameId  $id,
        private Ruleset $ruleset
    )
    {
    }

    public static function create(Ruleset $ruleset): self
    {
        return new self(GameId::new(), $ruleset);
    }

    public function id(): GameId
    {
        return $this->id;
    }

    public function ruleset(): Ruleset
    {
        return $this->ruleset;
    }

    public function status(): GameStatus
    {
        return $this->status;
    }

    public static function fromSnapshot(GameId $id, Ruleset $ruleset, GameStatus $status): self
    {
        $self = new self($id, $ruleset);
        $self->status = $status; // kontrolujemy wartości w repo/mapperze
        return $self;
    }

    /** @return Ship[]|null */
    public function fleet(): ?array
    {
        return $this->fleet;
    }

    /**
     * Używane przy odczycie ze snapshotu (bez walidacji biznesowej).
     * @param Ship[] $ships
     */
    public function setFleetFromSnapshot(array $ships): void
    {
        $this->fleet = $ships;
    }

    /**
     * @param Ship[] $ships
     */
    public function placeFleet(array $ships): void
    {
        if ($this->fleet !== null) {
            throw new \DomainException('Fleet already placed');
        }

        // walidacja zestawu statków wg ruleset
        $expected = $this->ruleset->allowedShips();
        $got = [];
        foreach ($ships as $s) {
            $got[$s->length] = ($got[$s->length] ?? 0) + 1;
        }
        ksort($expected); ksort($got);
        if ($got !== $expected) {
            throw new \DomainException('Invalid fleet composition');
        }

        // walidacja pozycji na planszy
        $board = new Board($this->ruleset->boardSize());
        $board->placeMany($ships);

        $this->fleet = $ships;
        $this->status = GameStatus::InProgress; // opcjonalnie: uznajmy, że po rozstawieniu gra startuje
    }
}
