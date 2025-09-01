<?php

namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\Game\{
    BoardSize, ClassicRuleset, Game, Ruleset, GameStatus, Ship, Orientation, Coordinate
};
use App\Domain\Shared\GameId;

final class GameSnapshotMapper
{
    public function toArray(Game $game): array
    {
        $size = $game->ruleset()->boardSize();

        $fleet = null;
        if ($game->fleet() !== null) {
            $fleet = array_map(function (Ship $s) {
                return [
                    'x' => $s->start->x,
                    'y' => $s->start->y,
                    'o' => $s->orientation->value,
                    'l' => $s->length,
                ];
            }, $game->fleet());
        }

        return [
            'status'  => $game->status()->value,
            'ruleset' => [
                'type'  => 'classic',
                'board' => ['w' => $size->width, 'h' => $size->height],
            ],
            'fleet' => $fleet,
        ];
    }

    public function toDomain(string $id, array $state): Game
    {
        $ruleset = $this->rulesetFromArray($state['ruleset'] ?? []);
        $status  = GameStatus::from((string)($state['status'] ?? GameStatus::Pending->value));
        $game    = Game::fromSnapshot(new GameId($id), $ruleset, $status);

        if (isset($state['fleet']) && is_array($state['fleet'])) {
            $ships = [];
            foreach ($state['fleet'] as $s) {
                $ships[] = new Ship(
                    new Coordinate((int)$s['x'], (int)$s['y']),
                    Orientation::from((string)$s['o']),
                    (int)$s['l']
                );
            }
            $game->setFleetFromSnapshot($ships);
        }

        return $game;
    }

    private function rulesetFromArray(array $data): Ruleset
    {
        $board = $data['board'] ?? ['w' => 10, 'h' => 10];
        return new ClassicRuleset(new BoardSize((int)$board['w'], (int)$board['h']));
    }
}
