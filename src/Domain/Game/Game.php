<?php

namespace App\Domain\Game;

use App\Domain\Game\Boarding\BoardingAttempt;
use App\Domain\Game\Boarding\BoardingOdds;
use App\Domain\Game\Boarding\BoardingRoll;
use App\Domain\Game\Boarding\BoardingTarget;
use App\Domain\Game\Boarding\CaptainBoardingEdge;
use App\Domain\Game\Boarding\HalfHullHit;
use App\Domain\Game\Siege\Fort;
use App\Domain\Game\Siege\FortPiece;
use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\WeaponKind;
use App\Domain\Game\Weapon\WeaponSpecs;
use App\Domain\Shared\GameId;

/**
 * Bitwa. Broń wynika z wyposażenia floty (Ship::$loadout): każdy statek wnosi
 * do puli swoje użycia i jest nośnikiem swojej broni. Gdy zatonie ostatni
 * nośnik danej broni, broń jest niedostępna do końca bitwy. Domyślne
 * wyposażenie wynika z długości (Loadout::defaultFor), warianty z rulesetu.
 */
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

    /**
     * Energia zużyta przez stronę w jej bieżącej turze (#59). Trzymamy zużycie,
     * nie stan puli: brak wpisu (także w starym snapshotcie) = pełna pula.
     *
     * @var array{player: int, opponent: int}
     */
    private array $energySpent = ['player' => 0, 'opponent' => 0];

    /** Czy gracz przesunął już statek w bieżącej turze (#60: ruch raz na turę). */
    private bool $playerMoved = false;

    /** Numer tury gracza, od 1; rośnie przy każdym powrocie tury do gracza (#118: seed rzutu abordażu). */
    private int $round = 1;

    /** Czy bitwa dopuszcza abordaż (#118): bitwy wyprawy z bronią specjalną. */
    private bool $boardingEnabled = false;

    /** O ile perk kapitana obniża próg abordażu (#161); 0 = bez perku. */
    private int $boardingEdge = 0;

    /**
     * Dodatkowa energia gracza w jego pierwszej turze (perk kapitana, #161)
     * i numer tej tury (round); w obronie pierwszą turę ma AI, więc gracz
     * dostaje premię w drugiej.
     *
     * @var array{bonus:int, round:int}|null
     */
    private ?array $firstTurnEnergy = null;

    /** Jedyna w bitwie próba abordażu; null = jeszcze nie było. */
    private ?BoardingAttempt $boarding = null;

    /** Fort na planszy wroga w bitwie oblężniczej (#119); null = zwykła bitwa z flotą. */
    private ?Fort $fort = null;

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

        if (null !== $this->fort) {
            return 0 === $this->fortGunsLeft();
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

    /**
     * Oddaje turę stronie i odnawia jej energię: pula wraca po ruchu
     * przeciwnika (#59). 'none' = koniec bitwy, bez odnawiania.
     */
    public function passTurnTo(string $turn): void
    {
        $this->setTurn($turn);
        if ('player' === $turn || 'opponent' === $turn) {
            $this->energySpent[$turn] = 0;
        }
        if ('player' === $turn) {
            $this->playerMoved = false;
            ++$this->round;
        }
    }

    /** Czy w tej bitwie działania kosztują energię (tryb z bronią specjalną). */
    public function hasEnergy(): bool
    {
        return $this->ruleset->energyPerTurn() > 0;
    }

    /** Energia strony ('player'|'opponent') pozostała w jej bieżącej turze. */
    public function energyLeft(string $side): int
    {
        return max(0, $this->energyMax($side) - $this->energySpent[$side]);
    }

    /** Pula strony w bieżącej turze: stała z rulesetu plus premia pierwszej tury gracza. */
    private function energyMax(string $side): int
    {
        return $this->ruleset->energyPerTurn() + ('player' === $side ? $this->firstTurnEnergyBonus() : 0);
    }

    /**
     * Premia energii w pierwszej turze gracza (perk kapitana, #161). Wołana przy
     * starcie bitwy, po ustaleniu, kto strzela pierwszy; bez energii nic nie robi.
     */
    public function grantFirstTurnEnergy(int $bonus): void
    {
        if ($bonus <= 0 || !$this->hasEnergy()) {
            return;
        }
        $this->firstTurnEnergy = ['bonus' => $bonus, 'round' => 'player' === $this->turn ? $this->round : $this->round + 1];
    }

    /** Premia energii gracza działająca w bieżącej turze; 0 poza jego pierwszą turą. */
    public function firstTurnEnergyBonus(): int
    {
        return null !== $this->firstTurnEnergy && $this->firstTurnEnergy['round'] === $this->round ? $this->firstTurnEnergy['bonus'] : 0;
    }

    /** @return array{bonus:int, round:int}|null premia pierwszej tury (do snapshotu) */
    public function firstTurnEnergy(): ?array
    {
        return $this->firstTurnEnergy;
    }

    /** @param array<string,mixed> $data premia ze snapshotu */
    public function setFirstTurnEnergyFromSnapshot(array $data): void
    {
        $bonus = (int) ($data['bonus'] ?? 0);
        $this->firstTurnEnergy = $bonus > 0 ? ['bonus' => $bonus, 'round' => max(1, (int) ($data['round'] ?? 1))] : null;
    }

    /** Czy stronę stać na działanie w tej turze (bez energii — zawsze). */
    public function canAfford(string $side, BattleAction $action): bool
    {
        return !$this->hasEnergy() || $this->energyLeft($side) >= $action->cost();
    }

    /**
     * Pobiera koszt działania z puli strony; bez energii w bitwie nic nie robi.
     * Limity użyć broni na bitwę sprawdza samo działanie — energia działa obok nich.
     */
    public function spendEnergy(string $side, BattleAction $action): void
    {
        if (!$this->hasEnergy()) {
            return;
        }
        if (!$this->canAfford($side, $action)) {
            throw new \DomainException('Not enough energy');
        }
        $this->energySpent[$side] += $action->cost();
    }

    /**
     * Energia gracza do API: pozostała, pula (z premią) i koszty działań;
     * bonus = część puli z perku kapitana w tej turze. Null bez energii.
     *
     * @return array{left:int, max:int, bonus:int, costs:array<string,int>}|null
     */
    public function energyState(): ?array
    {
        if (!$this->hasEnergy()) {
            return null;
        }

        return [
            'left' => $this->energyLeft('player'),
            'max' => $this->energyMax('player'),
            'bonus' => $this->firstTurnEnergyBonus(),
            'costs' => BattleAction::costs(),
        ];
    }

    /** @return array{player: int, opponent: int} zużycie w bieżącej turze (do snapshotu) */
    public function energySpent(): array
    {
        return $this->energySpent;
    }

    /** @param array<string,mixed> $spent zużycie ze snapshotu; brak strony = pełna pula */
    public function setEnergySpentFromSnapshot(array $spent): void
    {
        foreach (['player', 'opponent'] as $side) {
            $this->energySpent[$side] = max(0, (int) ($spent[$side] ?? 0));
        }
    }

    /** @return Ship[]|null */
    public function fleet(): ?array
    {
        return $this->playerSide->fleet();
    }

    /**
     * Ruch statku gracza o 1 pole (#60). Tylko w bitwie z energią i najwyżej
     * raz na turę; reguły ruchu (oś, trafienia, ostrzelane pola, granice, styk)
     * sprawdza strona gracza. Energię i turę pilnuje warstwa aplikacji, jak
     * przy sonarze. AI się nie rusza i nie dostaje informacji o ruchu.
     */
    public function moveShip(Coordinate $cell, Direction $direction): Ship
    {
        if (!$this->hasEnergy()) {
            throw new \DomainException('Ship move not available');
        }
        if (!$this->playerSide->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }
        if ($this->playerMoved) {
            throw new \DomainException('Ship already moved this turn');
        }

        $moved = $this->playerSide->moveShip($cell, $direction);
        $this->playerMoved = true;

        return $moved;
    }

    /** Czy gracz wykorzystał ruch statku w tej turze (do API i snapshotu). */
    public function playerMovedThisTurn(): bool
    {
        return $this->playerMoved;
    }

    public function setPlayerMovedFromSnapshot(bool $moved): void
    {
        $this->playerMoved = $moved;
    }

    // --- Abordaż (#118) ---

    public function round(): int
    {
        return $this->round;
    }

    public function setRoundFromSnapshot(int $round): void
    {
        $this->round = max(1, $round);
    }

    /**
     * Włącza abordaż w tej bitwie; robi to start bitwy wyprawy, nie bitwa
     * treningowa. $edge = obniżenie progu z perku kapitana (#161).
     */
    public function enableBoarding(int $edge = 0): void
    {
        $this->boardingEnabled = true;
        $this->boardingEdge = max(0, $edge);
    }

    public function boardingEdge(): int
    {
        return $this->boardingEdge;
    }

    /** Modyfikatory progu tej bitwy: bazowe i perk kapitana, jeśli go ma. */
    private function boardingOdds(): BoardingOdds
    {
        $modifiers = [new HalfHullHit()];
        if ($this->boardingEdge > 0) {
            $modifiers[] = new CaptainBoardingEdge($this->boardingEdge);
        }

        return new BoardingOdds($modifiers);
    }

    public function boardingEnabled(): bool
    {
        return $this->boardingEnabled;
    }

    public function boardingAttempt(): ?BoardingAttempt
    {
        return $this->boarding;
    }

    public function setBoardingAttemptFromSnapshot(?BoardingAttempt $attempt): void
    {
        $this->boarding = $attempt;
    }

    /**
     * Powód, dla którego abordaż jest teraz niedostępny; null = można próbować.
     * Turę i koniec gry pilnuje warstwa aplikacji, jak przy innych działaniach.
     */
    public function boardingBlocker(): ?string
    {
        // w oblężeniu (#119) wróg to konstrukcje fortu, nie statki do przejęcia
        if (!$this->boardingEnabled || !$this->hasEnergy() || null !== $this->fort) {
            return 'Boarding not available';
        }
        if (null !== $this->boarding) {
            return 'Boarding already attempted';
        }
        if (null === $this->boardingTarget()) {
            return 'Boarding requires the last enemy ship';
        }
        if (!$this->canAfford('player', BattleAction::Board)) {
            return 'Not enough energy';
        }

        return null;
    }

    /**
     * Próba abordażu ostatniego statku wroga: rzut k6 z seeda gry i numeru tury.
     * Sukces kończy bitwę wygraną, statek zostaje zdobyczą; porażka nic nie
     * zmienia na planszy. Energię i koniec tury rozlicza warstwa aplikacji.
     */
    public function attemptBoarding(?BoardingOdds $odds = null): BoardingAttempt
    {
        $odds ??= $this->boardingOdds();
        $blocker = $this->boardingBlocker();
        if (null !== $blocker) {
            throw new \DomainException($blocker);
        }

        [$index, $target] = $this->boardingTarget() ?? throw new \LogicException('Boarding target vanished');
        $this->boarding = new BoardingAttempt(
            $this->round,
            BoardingRoll::d6((string) $this->id, $this->round),
            $odds->threshold($target),
            $odds->applied($target),
            $index,
        );
        if ($this->boarding->succeeded()) {
            $this->status = GameStatus::Won;
        }

        return $this->boarding;
    }

    /** Statek wroga zdobyty abordażem; null = nie było udanej próby. */
    public function boardedShip(): ?Ship
    {
        if (null === $this->boarding || !$this->boarding->succeeded()) {
            return null;
        }

        return $this->opponentSide->fleet()[$this->boarding->shipIndex] ?? null;
    }

    /**
     * Stan abordażu do API: dostępność, próg i modyfikatory dla bieżącego celu
     * (przy kilku statkach wroga — bazowe), wynik próby; null = bitwa bez abordażu (także oblężenie).
     *
     * @return array{available:bool, blocker:?string, threshold:int, modifiers:list<array{code:string, value:int}>, cost:int, attempt:array<string,mixed>|null}|null
     */
    public function boardingState(?BoardingOdds $odds = null): ?array
    {
        $odds ??= $this->boardingOdds();
        if (!$this->boardingEnabled || !$this->hasEnergy() || null !== $this->fort) {
            return null;
        }

        $target = $this->boardingTarget();
        $blocker = $this->boardingBlocker();

        return [
            'available' => null === $blocker,
            'blocker' => match ($blocker) {
                null => null,
                'Boarding already attempted' => 'attempted',
                'Boarding requires the last enemy ship' => 'not-last-ship',
                'Not enough energy' => 'energy',
                default => 'not-available',
            },
            'threshold' => null !== $target ? $odds->threshold($target[1]) : max(BoardingOdds::MIN_THRESHOLD, BoardingOdds::BASE_THRESHOLD - $this->boardingEdge),
            // bez celu (kilka statków wroga) działa tylko perk kapitana — nie zależy od statku
            'modifiers' => null !== $target ? $odds->applied($target[1]) : ($this->boardingEdge > 0 ? [['code' => 'captain', 'value' => -$this->boardingEdge]] : []),
            'cost' => BattleAction::Board->cost(),
            'attempt' => $this->boarding?->toArray(),
        ];
    }

    /** @return array{0:int, 1:BoardingTarget}|null ostatni niezatopiony statek wroga z indeksem */
    private function boardingTarget(): ?array
    {
        if (!$this->opponentSide->hasFleet()) {
            return null;
        }
        $afloat = $this->opponentSide->unsunkShips();
        if (1 !== count($afloat)) {
            return null;
        }
        $index = array_key_first($afloat);

        return [$index, new BoardingTarget($afloat[$index]['ship'], $afloat[$index]['hits'])];
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

        $this->assertFleetComposition($ships, $this->ruleset->allowedShips(), $this->ruleset->variants());
        $this->playerSide->placeFleet($ships);
        $this->status = GameStatus::InProgress;
    }

    /**
     * Ustawia flotę przeciwnika (walidowana jak flota gracza, ale względem jego
     * składu z rulesetu) i buduje planszę przeciwnika.
     *
     * @param Ship[] $ships
     */
    public function placeOpponentFleet(array $ships): void
    {
        if ($this->opponentSide->hasFleet()) {
            throw new \DomainException('Opponent fleet already placed');
        }

        $this->assertFleetComposition($ships, $this->ruleset->opponentShips(), $this->ruleset->opponentVariants());
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

        if (null !== $this->fort) {
            $this->resolveFortShot($c, $result);

            return $result;
        }

        if ($target->allShipsHit()) {
            $this->status = GameStatus::Won;
        }

        return $result;
    }

    // --- Oblężenie fortu (#119) ---

    /**
     * Stawia fort na planszy wroga zamiast floty AI (bitwa oblężnicza).
     * Konstrukcje są statkami bez broni, więc fort nie ma broni specjalnych;
     * w przeciwieństwie do floty mogą stać na styk (bez nakładania).
     */
    public function placeFort(Fort $fort): void
    {
        if ($this->opponentSide->hasFleet()) {
            throw new \DomainException('Opponent fleet already placed');
        }
        $this->opponentSide->allowContact();
        $this->opponentSide->placeFleet($fort->toShips());
        $this->fort = $fort;
    }

    /**
     * Odtworzenie ze snapshotu: plansza wroga wraca z opponentFleet, tu tylko
     * opis fortu. Wołać PRZED setOpponentFleetFromSnapshot (konstrukcje na styk).
     */
    public function setFortFromSnapshot(Fort $fort): void
    {
        $this->opponentSide->allowContact();
        $this->fort = $fort;
    }

    public function fort(): ?Fort
    {
        return $this->fort;
    }

    /** Działa fortu, które jeszcze stoją; fort strzela, dopóki to > 0. */
    public function fortGunsLeft(): int
    {
        if (null === $this->fort) {
            return 0;
        }

        return count(array_filter(
            $this->fort->guns,
            fn (Coordinate $gun) => $this->opponentSide->hasUnsunkShipAt($gun->x, $gun->y),
        ));
    }

    /**
     * Czy magazyn prochu wybuchł (trafiony). Eksplozja nie ma osobnego stanu:
     * wynika z trafienia magazynu, a jej pola są zwykłymi strzałami.
     */
    public function fortExploded(): bool
    {
        if (null === $this->fort) {
            return false;
        }

        return $this->opponentSide->hasShipAt($this->fort->magazine->x, $this->fort->magazine->y)
            && !$this->opponentSide->hasUnsunkShipAt($this->fort->magazine->x, $this->fort->magazine->y);
    }

    /**
     * Skutki strzału w fort: trafiony magazyn wybucha i trafia pola 3×3 wokół
     * siebie (działa i mury też); mur tylko pochłania strzał. Wygrana dopiero
     * po zniszczeniu ostatniego działa.
     */
    private function resolveFortShot(Coordinate $c, ShotResult $result): void
    {
        \assert(null !== $this->fort);
        if (ShotResult::Sunk === $result && FortPiece::Magazine === $this->fort->pieceAt($c->x, $c->y)) {
            $size = $this->ruleset->boardSize();
            foreach ($this->fort->blastCells($size->width, $size->height) as $cell) {
                $this->opponentSide->receiveShot($cell);
            }
        }

        if (0 === $this->fortGunsLeft()) {
            $this->status = GameStatus::Won;
        }
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
     * Stan broni specjalnych gracza: użycia i limity z rulesetu, a sonar
     * dodatkowo z promieniem krzyża (zależy od technologii gracza, Epic G).
     *
     * @return array<string, array{used:int, limit:int, radius?:int, bonus?:int}>
     */
    public function weaponsState(): array
    {
        return $this->weaponsStateFor('player');
    }

    /**
     * Stan broni specjalnych przeciwnika (AI).
     *
     * @return array<string, array{used:int, limit:int, radius?:int, bonus?:int}>
     */
    public function opponentWeaponsState(): array
    {
        return $this->weaponsStateFor('opponent');
    }

    /** @return array<string, array{used:int, limit:int, radius?:int, bonus?:int}> */
    private function weaponsStateFor(string $shooter): array
    {
        $out = [];
        foreach ($this->weaponsOf($shooter)->limits() as $weapon => $limit) {
            $out[$weapon] = ['used' => $this->weaponUses[$shooter][$weapon] ?? 0, 'limit' => $limit];
        }
        $out['sonar']['radius'] = $this->weaponsOf($shooter)->sonar->radius;
        // użycia sonaru z perku kapitana (#161), już wliczone w limit
        if ('player' === $shooter && $this->ruleset instanceof FunRuleset && $this->ruleset->sonarBonusApplied() > 0) {
            $out['sonar']['bonus'] = $this->ruleset->sonarBonusApplied();
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

    /** Specyfikacje broni strony: limity wynikają z jej własnego składu floty. */
    private function weaponsOf(string $shooter): WeaponSpecs
    {
        return 'opponent' === $shooter ? $this->ruleset->opponentWeapons() : $this->ruleset->weapons();
    }

    /** Rzuca, gdy broń niedostępna w rulesecie albo limit danej strony wyczerpany. */
    private function assertWeaponAvailable(string $shooter, string $weapon): void
    {
        $limit = $this->weaponsOf($shooter)->limits()[$weapon] ?? 0;
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

        $limit = $this->weaponsOf($shooter)->torpedo->diagonalUses;
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

        $spec = $this->weaponsOf($shooter)->sonar;
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

        if (!$this->playerSide->hasUnsunkCarrierAt($start->x, $start->y, WeaponKind::Torpedo)) {
            throw new \DomainException('Torpedo must be launched from an unsunk destroyer');
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
        $this->assertWeaponCarrierAfloat($this->playerSide, WeaponKind::Sonar, 'Sonar requires an unsunk scout ship');
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
        $this->assertWeaponCarrierAfloat($this->playerSide, WeaponKind::AirRaid, 'Air raid requires an unsunk carrier');

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
     * Komórki niezatopionych nośników torped AI — legalne wyrzutnie jego torped.
     *
     * @return list<array{x:int,y:int}>
     */
    public function opponentLaunchableCells(): array
    {
        return $this->opponentSide->unsunkShipCells(WeaponKind::Torpedo);
    }

    /** Czy AI ma jeszcze niezatopiony nośnik danej broni. */
    public function opponentHasWeaponCarrier(WeaponKind $weapon): bool
    {
        return $this->opponentSide->hasUnsunkCarrierOf($weapon);
    }

    /** Rzuca, gdy zatonął ostatni nośnik broni danej strony. */
    private function assertWeaponCarrierAfloat(BoardSide $side, WeaponKind $weapon, string $message): void
    {
        if (!$side->hasUnsunkCarrierOf($weapon)) {
            throw new \DomainException($message);
        }
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

        if (!$this->opponentSide->hasUnsunkCarrierAt($start->x, $start->y, WeaponKind::Torpedo)) {
            throw new \DomainException('Torpedo must be launched from an unsunk destroyer');
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
        $this->assertWeaponCarrierAfloat($this->opponentSide, WeaponKind::Sonar, 'Sonar requires an unsunk scout ship');
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
        $this->assertWeaponCarrierAfloat($this->opponentSide, WeaponKind::AirRaid, 'Air raid requires an unsunk carrier');

        $max = $this->ruleset->opponentWeapons()->airRaid->maxArea;
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

    /**
     * Flota musi zgadzać się ze składem co do długości i wyposażenia statków.
     *
     * @param Ship[]                   $ships
     * @param array<int,int>           $expected
     * @param array<int,list<Loadout>> $variants
     */
    private function assertFleetComposition(array $ships, array $expected, array $variants): void
    {
        $count = static function (array $entries): array {
            $out = [];
            foreach ($entries as [$length, $loadout]) {
                $key = $length.'|'.$loadout->key();
                $out[$key] = ($out[$key] ?? 0) + 1;
            }
            ksort($out);

            return $out;
        };

        $got = $count(array_map(static fn (Ship $s) => [$s->length, $s->loadout], $ships));
        $want = $count(array_map(
            static fn (array $s) => [$s['length'], $s['loadout']],
            FleetComposition::roster($expected, $variants),
        ));
        if ($got !== $want) {
            throw new \DomainException('Invalid fleet composition');
        }
    }
}
