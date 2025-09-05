<?php

namespace App\Domain\Game;

final class TurnLoop
{
    public function __construct(
        private Shooter     $player1,         // np. HumanShooter albo drugi AI
        private TargetBoard $player2Board, // plansza przeciwnika P2
        private Shooter     $player2,          // AI
        private TargetBoard $player1Board  // plansza P1
    ) {
    }

    /** Zwraca zwycięzcę: 1 lub 2 */
    public function play(): int
    {
        $p1View = new BoardReadModelAdapter($this->player2Board);
        $p2View = new BoardReadModelAdapter($this->player1Board);

        // globalny bezpiecznik – nie więcej niż 4 * N^2 strzałów w całej partii
        $N = max($p1View->size(), $p2View->size());
        $maxOverallShots = max(1, 4 * $N * $N);
        $overall = 0;

        $turn = 1;
        while (true) {
            if ($turn === 1) {
                // Tura gracza 1 – strzela dopóki trafia (Hit/Sunk)
                $shotsThisTurn = 0;
                $maxThisTurn = $p1View->size() * $p1View->size(); // bezpiecznik tury
                do {
                    $c = $this->player1->nextShot($p1View);
                    $res = $this->player2Board->shoot($c);
                    $this->player1->notify($c, $res);

                    if ($this->player2Board->isDefeated()) {
                        return 1;
                    }

                    $shotsThisTurn++;
                    $overall++;
                    if ($shotsThisTurn >= $maxThisTurn) {
                        break;
                    }
                    if ($overall >= $maxOverallShots) {
                        throw new \RuntimeException('TurnLoop safety triggered: too many shots overall');
                    }
                } while ($res === ShotResult::Hit || $res === ShotResult::Sunk);

                $turn = 2;
            } else {
                // ... existing code ...
                // Tura gracza 2 – strzela dopóki trafia (Hit/Sunk)
                $shotsThisTurn = 0;
                $maxThisTurn = $p2View->size() * $p2View->size(); // bezpiecznik tury
                do {
                    $c = $this->player2->nextShot($p2View);
                    $res = $this->player1Board->shoot($c);
                    $this->player2->notify($c, $res);

                    if ($this->player1Board->isDefeated()) {
                        return 2;
                    }

                    $shotsThisTurn++;
                    $overall++;
                    if ($shotsThisTurn >= $maxThisTurn) {
                        break;
                    }
                    if ($overall >= $maxOverallShots) {
                        throw new \RuntimeException('TurnLoop safety triggered: too many shots overall');
                    }
                } while ($res === ShotResult::Hit || $res === ShotResult::Sunk);

                $turn = 1;
            }
        }
    }
}
