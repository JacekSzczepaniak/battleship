<?php

namespace App\Domain\Expedition;

use App\Domain\Game\Board;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use Symfony\Component\Uid\Uuid;

/**
 * Zapisany zestaw floty (#125): skład (id statków floty) z rozmieszczeniem na
 * planszy danego rozmiaru. Pozycje spełniają zasady planszy (granice, statki się
 * nie stykają) w chwili zapisu. Typ statku to migawka z zapisu — zestaw przeżywa
 * utratę albo złomowanie statku, a UI wie, czego brakuje.
 */
final class FleetPreset
{
    public const NAME_MAX_LENGTH = 30;

    /**
     * @param list<array{shipId: string, type: ShipType, x: int, y: int, o: Orientation}> $ships
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $boardWidth,
        public readonly int $boardHeight,
        public readonly array $ships,
    ) {
    }

    /**
     * Nowy zestaw ze sprawdzeniem zasad planszy; typy statków podaje profil (flota).
     *
     * @param list<array{shipId: string, type: ShipType, x: int, y: int, o: Orientation}> $ships
     */
    public static function create(?string $id, string $name, int $boardWidth, int $boardHeight, array $ships): self
    {
        $name = self::normalizeName($name);
        if ([] === $ships) {
            throw new \InvalidArgumentException('Fleet preset must place at least one ship');
        }
        $shipIds = array_column($ships, 'shipId');
        if (count($shipIds) !== count(array_unique($shipIds))) {
            throw new \InvalidArgumentException('Squad must list distinct ships');
        }

        // te same zasady co rozstawienie floty w grze (Board::touchOrOverlap)
        $board = new Board(new BoardSize($boardWidth, $boardHeight));
        foreach ($ships as $ship) {
            $board->place(new Ship(new Coordinate($ship['x'], $ship['y']), $ship['o'], $ship['type']->length()));
        }

        return new self($id ?? Uuid::v4()->toRfc4122(), $name, $boardWidth, $boardHeight, $ships);
    }

    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Fleet preset name must be 1-%d characters', self::NAME_MAX_LENGTH));
        }

        return $name;
    }

    public function renamed(string $name): self
    {
        return new self($this->id, self::normalizeName($name), $this->boardWidth, $this->boardHeight, $this->ships);
    }

    public function hasName(string $name): bool
    {
        return mb_strtolower($this->name) === mb_strtolower(trim($name));
    }

    /** @return array{id: string, name: string, board: array{w: int, h: int}, ships: list<array{shipId: string, type: string, x: int, y: int, o: string}>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'board' => ['w' => $this->boardWidth, 'h' => $this->boardHeight],
            'ships' => array_map(static fn (array $s) => [
                'shipId' => $s['shipId'],
                'type' => $s['type']->value,
                'x' => $s['x'],
                'y' => $s['y'],
                'o' => $s['o']->value,
            ], $this->ships),
        ];
    }
}
