<?php

namespace App\Domain\Game;

use App\Domain\Shared\GameId;

final class Game
{
    private GameStatus $status = GameStatus::Pending;

    /** Strona gracza: jego flota + strzały przeciwnika (AI) w nią oddane. */
    private BoardSide $playerSide;

    /** Strona przeciwnika: jego flota + strzały gracza w nią oddane. */
    private BoardSide $opponentSide;

    // Iteration 1: minimalne meta gry (na razie jako proste stringi)
    private string $mode = 'standard';      // 'standard' | 'nonstandard'
    private string $opponent = 'mock';      // 'mock' | 'ai' | 'pvp'
    private string $turn = 'player';        // 'player' | 'opponent'

    /**
     * Nieprzezroczysty stan AI przeciwnika (kształt zna wyłącznie implementacja AI).
     * Game tylko przechowuje go między requestami na potrzeby snapshotu.
     *
     * @var array<string,mixed>
     */
    private array $aiState = [];

    /**
     * Liczba użyć broni specjalnych per strona (klucze zewn.: player|opponent,
     * wewn.: torpedo|sonar|airRaid). Limity per grę i stronę definiują
     * specyfikacje broni z Ruleset::weapons(); 0 = broń niedostępna.
     *
     * @var array{player: array<string,int>, opponent: array<string,int>}
     */
    private array $weaponUses = ['player' => [], 'opponent' => []];

    public function __construct(
        private GameId $id,
        private Ruleset $ruleset,
    ) {
        $this->playerSide = new BoardSide($ruleset->boardSize());
        $this->opponentSide = new BoardSide($ruleset->boardSize());
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

    /**
     * Returns whether the game is finished.
     * Checks status and (as a safeguard) actual hits on all ship cells.
     */
    public function isFinished(): bool
    {
        if (in_array($this->status, [GameStatus::Won, GameStatus::Lost], true)) {
            return true;
        }

        return $this->targetSide()->allShipsHit();
    }

    public static function fromSnapshot(GameId $id, Ruleset $ruleset, GameStatus $status): self
    {
        $self = new self($id, $ruleset);
        $self->status = $status;

        return $self;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function opponent(): string
    {
        return $this->opponent;
    }

    public function setOpponent(string $opponent): void
    {
        $this->opponent = $opponent;
    }

    public function turn(): string
    {
        return $this->turn;
    }

    public function setTurn(string $turn): void
    {
        if (!in_array($turn, ['player', 'opponent', 'none'], true)) {
            throw new \InvalidArgumentException('Invalid turn value');
        }
        $this->turn = $turn;
    }

    /** @return Ship[]|null */
    public function fleet(): ?array
    {
        return $this->playerSide->fleet();
    }

    /**
     * Used when loading from a snapshot (no business validation).
     *
     * @param Ship[] $ships
     */
    public function setFleetFromSnapshot(array $ships): void
    {
        $this->playerSide->setFleetFromSnapshot($ships);
    }

    /**
     * @param Ship[] $ships
     */
    public function placeFleet(array $ships): void
    {
        if ($this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet already placed');
        }

        $this->assertFleetComposition($ships);
        $this->playerSide->placeFleet($ships);
        $this->status = GameStatus::InProgress;
    }

    /**
     * Ustawia flotę przeciwnika (walidowana jak flota gracza) i buduje planszę przeciwnika.
     *
     * @param Ship[] $ships
     */
    public function placeOpponentFleet(array $ships): void
    {
        if ($this->opponentSide->hasFleet()) {
            throw new \DomainException('Opponent fleet already placed');
        }

        $this->opponentSide->placeFleet($ships);
    }

    /**
     * Używane przy odtwarzaniu ze snapshotu (bez walidacji biznesowej poza budową planszy).
     *
     * @param Ship[] $ships
     */
    public function setOpponentFleetFromSnapshot(array $ships): void
    {
        $this->opponentSide->setFleetFromSnapshot($ships);
    }

    /** @return Ship[]|null */
    public function opponentFleet(): ?array
    {
        return $this->opponentSide->fleet();
    }

    /**
     * Strzał gracza w stronę przeciwnika. Gdy wszystkie statki celu trafione — wygrana.
     *
     * Throws DomainException when no fleet to shoot at is placed.
     */
    public function fireShot(Coordinate $c): ShotResult
    {
        $target = $this->targetSide();
        if (!$target->hasFleet()) {
            throw new \DomainException('Opponent fleet not placed');
        }

        $result = $target->receiveShot($c);

        if ($target->allShipsHit()) {
            $this->status = GameStatus::Won;
        }

        return $result;
    }

    /**
     * Strzał przeciwnika (AI) w stronę gracza. Gdy cała flota gracza trafiona — przegrana.
     */
    public function fireOpponentShot(Coordinate $c): ShotResult
    {
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $result = $this->playerSide->receiveShot($c);

        if ($this->playerSide->allShipsHit()) {
            $this->status = GameStatus::Lost;
        }

        return $result;
    }

    /**
     * Widok planszy gracza z perspektywy AI przeciwnika: rozmiar + pola już ostrzelane.
     * Nie odsłania pozycji statków.
     */
    public function opponentShotsView(): BoardReadModel
    {
        return $this->playerSide->shotsView();
    }

    /** @return array<string,mixed> */
    public function aiState(): array
    {
        return $this->aiState;
    }

    /** @param array<string,mixed> $state */
    public function setAiState(array $state): void
    {
        $this->aiState = $state;
    }

    /**
     * Stan broni specjalnych gracza: użycia i limity z rulesetu.
     *
     * @return array<string, array{used:int, limit:int}>
     */
    public function weaponsState(): array
    {
        return $this->weaponsStateFor('player');
    }

    /**
     * Stan broni specjalnych przeciwnika (AI).
     *
     * @return array<string, array{used:int, limit:int}>
     */
    public function opponentWeaponsState(): array
    {
        return $this->weaponsStateFor('opponent');
    }

    /** @return array<string, array{used:int, limit:int}> */
    private function weaponsStateFor(string $shooter): array
    {
        $out = [];
        foreach ($this->ruleset->weapons()->limits() as $weapon => $limit) {
            $out[$weapon] = ['used' => $this->weaponUses[$shooter][$weapon] ?? 0, 'limit' => $limit];
        }

        return $out;
    }

    /** @return array{player: array<string,int>, opponent: array<string,int>} surowe liczniki (do snapshotu) */
    public function weaponUses(): array
    {
        return $this->weaponUses;
    }

    /**
     * Odtwarza liczniki użyć broni ze snapshotu. Obsługuje dwa kształty:
     * nowy (player/opponent) oraz starszy płaski (tylko użycia gracza).
     *
     * @param array<string,mixed> $uses
     */
    public function setWeaponUsesFromSnapshot(array $uses): void
    {
        $this->weaponUses = ['player' => [], 'opponent' => []];

        $isPerSide = isset($uses['player']) || isset($uses['opponent']);
        $sides = $isPerSide ? $uses : ['player' => $uses];

        foreach (['player', 'opponent'] as $side) {
            foreach ((array) ($sides[$side] ?? []) as $weapon => $count) {
                $this->weaponUses[$side][(string) $weapon] = max(0, (int) $count);
            }
        }
    }

    /** Rzuca, gdy broń niedostępna w rulesecie albo limit danej strony wyczerpany. */
    private function assertWeaponAvailable(string $shooter, string $weapon): void
    {
        $limit = $this->ruleset->weapons()->limits()[$weapon] ?? 0;
        if ($limit <= 0) {
            throw new \DomainException(sprintf('%s not available in this ruleset', ucfirst($weapon)));
        }
        if (($this->weaponUses[$shooter][$weapon] ?? 0) >= $limit) {
            throw new \DomainException(sprintf('%s limit reached', ucfirst($weapon)));
        }
    }

    /** Zużywa jedno użycie broni (po przejściu wszystkich walidacji). */
    private function consumeWeapon(string $shooter, string $weapon): void
    {
        $this->assertWeaponAvailable($shooter, $weapon);
        $this->weaponUses[$shooter][$weapon] = ($this->weaponUses[$shooter][$weapon] ?? 0) + 1;
    }

    /**
     * Torpeda ukośna ma osobny limit (zwiad — nie trafi jednego statku dwa razy,
     * za to rozrzuca trafienia po wielu; patrz Direction::isDiagonal()).
     */
    private function assertDiagonalTorpedoAvailable(string $shooter, Direction $direction): void
    {
        if (!$direction->isDiagonal()) {
            return;
        }

        $limit = $this->ruleset->weapons()->torpedo->diagonalUses;
        if (($this->weaponUses[$shooter]['torpedoDiagonal'] ?? 0) >= $limit) {
            throw new \DomainException('Diagonal torpedo limit reached');
        }
    }

    /** Zużycie torpedy: licznik ogólny + osobny dla przekątnych. */
    private function consumeTorpedo(string $shooter, Direction $direction): void
    {
        $this->consumeWeapon($shooter, 'torpedo');
        if ($direction->isDiagonal()) {
            $this->weaponUses[$shooter]['torpedoDiagonal'] = ($this->weaponUses[$shooter]['torpedoDiagonal'] ?? 0) + 1;
        }
    }

    /**
     * Efektywny promień sonaru: null = maksymalny ze specu; ponad spec — błąd.
     * Najpierw dostępność broni, żeby classic zgłaszał 'not available', nie zasięg.
     */
    private function resolveSonarRadius(string $shooter, ?int $radius): int
    {
        $this->assertWeaponAvailable($shooter, 'sonar');

        $spec = $this->ruleset->weapons()->sonar;
        $radius ??= $spec->radius;
        if ($radius > $spec->radius) {
            throw new \DomainException('Sonar radius exceeds ruleset limit');
        }

        return $radius;
    }

    /** Strzały gracza. @return list<array{x:int,y:int}> */
    public function shots(): array
    {
        return $this->targetSide()->shotsTaken();
    }

    /** Strzały przeciwnika (AI). @return list<array{x:int,y:int}> */
    public function opponentShots(): array
    {
        return $this->playerSide->shotsTaken();
    }

    /** @param array<int, array{x:int,y:int,r?:string}> $shots */
    public function setShotsFromSnapshot(array $shots): void
    {
        $this->targetSide()->setShotsFromSnapshot($shots);
    }

    /** @param array<int, array{x:int,y:int,r?:string}> $shots */
    public function setOpponentShotsFromSnapshot(array $shots): void
    {
        $this->playerSide->setShotsFromSnapshot($shots);
    }

    /**
     * Strzały gracza z wyliczonym wynikiem.
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function shotsWithResults(): array
    {
        return $this->targetSide()->shotsWithResults();
    }

    /**
     * Strzały przeciwnika (AI) z wyliczonym wynikiem.
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function opponentShotsWithResults(): array
    {
        return $this->playerSide->shotsWithResults();
    }

    /**
     * Torpedo: moves across the entire board in a given direction.
     * For each cell along the line it calls fireShot() and returns the list of results.
     *
     * Torpeda musi być odpalona z pozycji własnego, NIEZATOPIONEGO statku —
     * pozycje żywej floty gracza wyznaczają dostępne linie ostrzału.
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function fireTorpedo(Coordinate $start, Direction $direction): array
    {
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $this->assertWeaponAvailable('player', 'torpedo');
        $this->assertDiagonalTorpedoAvailable('player', $direction);

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        if ($start->x >= $w || $start->y >= $h) {
            throw new \DomainException('Torpedo start outside board');
        }

        if (!$this->playerSide->hasUnsunkShipAt($start->x, $start->y)) {
            throw new \DomainException('Torpedo must be launched from an unsunk ship');
        }

        $this->consumeTorpedo('player', $direction);

        [$dx, $dy] = $direction->vector();

        $results = [];

        // Include the start point and each subsequent point until hitting the edge (inclusive)
        $cx = $start->x;
        $cy = $start->y;
        while ($cx >= 0 && $cy >= 0 && $cx < $w && $cy < $h) {
            $r = $this->fireShot(new Coordinate($cx, $cy));
            $results[] = ['x' => $cx, 'y' => $cy, 'result' => $r->value];
            $cx += $dx;
            $cy += $dy;
        }

        return $results;
    }

    /**
     * Sonar ping: reveals occupancy info for the center and up to $radius cells
     * in each cardinal direction (cross shape). It does not modify shots/hits.
     * Skanuje stronę, w którą strzela gracz (przeciwnika; fallback jak fireShot).
     * Promień null = maksymalny z rulesetu; większy niż spec — błąd domenowy.
     *
     * @return list<array{x:int,y:int,occupied:bool}>
     */
    public function sonarPing(Coordinate $center, ?int $radius = null): array
    {
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $radius = $this->resolveSonarRadius('player', $radius);
        $this->consumeWeapon('player', 'sonar');

        $target = $this->targetSide();

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        $inBounds = static fn (int $x, int $y) => $x >= 0 && $y >= 0 && $x < $w && $y < $h;

        $cells = [];
        // center
        $cells[] = [$center->x, $center->y];

        // N/E/S/W up to radius
        for ($i = 1; $i <= $radius; ++$i) {
            $cells[] = [$center->x, $center->y - $i]; // N
            $cells[] = [$center->x + $i, $center->y]; // E
            $cells[] = [$center->x, $center->y + $i]; // S
            $cells[] = [$center->x - $i, $center->y]; // W
        }

        $results = [];
        foreach ($cells as [$x, $y]) {
            if (!$inBounds($x, $y)) {
                continue;
            }
            $results[] = ['x' => $x, 'y' => $y, 'occupied' => $target->hasShipAt($x, $y)];
        }

        return $results;
    }

    /**
     * Air raid: ostrzeliwuje prostokąt wokół punktu centralnego.
     * $area to pół-zasięgi (width → oś x, height → oś y), więc żądany obszar
     * ma (2*width+1) × (2*height+1) komórek; przy krawędzi jest przycinany do planszy.
     * Walidacja rozmiaru dotyczy obszaru ŻĄDANEGO (przed przycięciem) względem
     * limitu z rulesetu (airRaidSize = maksymalny pełny rozmiar w komórkach).
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function sendAirRaid(Coordinate $start, Area $area): array
    {
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        if ($start->x >= $w || $start->y >= $h) {
            throw new \DomainException('Air Raid start outside board');
        }

        $this->assertWeaponAvailable('player', 'airRaid');

        $max = $this->ruleset->weapons()->airRaid->maxArea;
        if (2 * $area->width + 1 > $max->width || 2 * $area->height + 1 > $max->height) {
            throw new \DomainException('Air Raid area is oversize');
        }

        $this->consumeWeapon('player', 'airRaid');

        $fromX = max(0, $start->x - $area->width);
        $toX = min($w - 1, $start->x + $area->width);
        $fromY = max(0, $start->y - $area->height);
        $toY = min($h - 1, $start->y + $area->height);

        $results = [];

        for ($x = $fromX; $x <= $toX; ++$x) {
            for ($y = $fromY; $y <= $toY; ++$y) {
                $r = $this->fireShot(new Coordinate($x, $y));
                $results[] = ['x' => $x, 'y' => $y, 'result' => $r->value];
            }
        }

        return $results;
    }

    // --- Bronie specjalne przeciwnika (AI) — lustrzane wobec broni gracza ---

    /**
     * Komórki niezatopionych statków AI — legalne wyrzutnie jego torped.
     *
     * @return list<array{x:int,y:int}>
     */
    public function opponentLaunchableCells(): array
    {
        return $this->opponentSide->unsunkShipCells();
    }

    /**
     * Torpeda AI: startuje z niezatopionego statku AI, ostrzeliwuje planszę
     * gracza wzdłuż kierunku. Ta sama reguła wyrzutni co u gracza.
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function fireOpponentTorpedo(Coordinate $start, Direction $direction): array
    {
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $this->assertWeaponAvailable('opponent', 'torpedo');
        $this->assertDiagonalTorpedoAvailable('opponent', $direction);

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        if ($start->x >= $w || $start->y >= $h) {
            throw new \DomainException('Torpedo start outside board');
        }

        if (!$this->opponentSide->hasUnsunkShipAt($start->x, $start->y)) {
            throw new \DomainException('Torpedo must be launched from an unsunk ship');
        }

        $this->consumeTorpedo('opponent', $direction);

        [$dx, $dy] = $direction->vector();

        $results = [];
        $cx = $start->x;
        $cy = $start->y;
        while ($cx >= 0 && $cy >= 0 && $cx < $w && $cy < $h) {
            $r = $this->fireOpponentShot(new Coordinate($cx, $cy));
            $results[] = ['x' => $cx, 'y' => $cy, 'result' => $r->value];
            $cx += $dx;
            $cy += $dy;
        }

        return $results;
    }

    /**
     * Sonar AI: skanuje planszę gracza (krzyż; promień jak u gracza — z rulesetu).
     * Bez zmian stanu strzałów.
     *
     * @return list<array{x:int,y:int,occupied:bool}>
     */
    public function opponentSonarPing(Coordinate $center, ?int $radius = null): array
    {
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $radius = $this->resolveSonarRadius('opponent', $radius);
        $this->consumeWeapon('opponent', 'sonar');

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        $inBounds = static fn (int $x, int $y) => $x >= 0 && $y >= 0 && $x < $w && $y < $h;

        $cells = [[$center->x, $center->y]];
        for ($i = 1; $i <= $radius; ++$i) {
            $cells[] = [$center->x, $center->y - $i];
            $cells[] = [$center->x + $i, $center->y];
            $cells[] = [$center->x, $center->y + $i];
            $cells[] = [$center->x - $i, $center->y];
        }

        $results = [];
        foreach ($cells as [$x, $y]) {
            if (!$inBounds($x, $y)) {
                continue;
            }
            $results[] = ['x' => $x, 'y' => $y, 'occupied' => $this->playerSide->hasShipAt($x, $y)];
        }

        return $results;
    }

    /**
     * Nalot AI: ostrzeliwuje prostokąt planszy gracza wokół centrum
     * (semantyka pół-zasięgów i limit rozmiaru jak u gracza).
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function sendOpponentAirRaid(Coordinate $start, Area $area): array
    {
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        if ($start->x >= $w || $start->y >= $h) {
            throw new \DomainException('Air Raid start outside board');
        }

        $this->assertWeaponAvailable('opponent', 'airRaid');

        $max = $this->ruleset->weapons()->airRaid->maxArea;
        if (2 * $area->width + 1 > $max->width || 2 * $area->height + 1 > $max->height) {
            throw new \DomainException('Air Raid area is oversize');
        }

        $this->consumeWeapon('opponent', 'airRaid');

        $fromX = max(0, $start->x - $area->width);
        $toX = min($w - 1, $start->x + $area->width);
        $fromY = max(0, $start->y - $area->height);
        $toY = min($h - 1, $start->y + $area->height);

        $results = [];
        for ($x = $fromX; $x <= $toX; ++$x) {
            for ($y = $fromY; $y <= $toY; ++$y) {
                $r = $this->fireOpponentShot(new Coordinate($x, $y));
                $results[] = ['x' => $x, 'y' => $y, 'result' => $r->value];
            }
        }

        return $results;
    }

    /**
     * Strona, w którą strzela gracz: przeciwnik, a gdy jego floty (jeszcze) nie ma —
     * wstecznie kompatybilny fallback na stronę gracza (starsze testy/snapshoty).
     */
    private function targetSide(): BoardSide
    {
        return $this->opponentSide->hasFleet() ? $this->opponentSide : $this->playerSide;
    }

    /** @param Ship[] $ships */
    private function assertFleetComposition(array $ships): void
    {
        $expected = $this->ruleset->allowedShips();
        $got = [];
        foreach ($ships as $s) {
            $got[$s->length] = ($got[$s->length] ?? 0) + 1;
        }
        ksort($expected);
        ksort($got);
        if ($got !== $expected) {
            throw new \DomainException('Invalid fleet composition');
        }
    }
}
