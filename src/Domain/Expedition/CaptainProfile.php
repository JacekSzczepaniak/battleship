<?php

namespace App\Domain\Expedition;

use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;

/**
 * Profil kapitana: doświadczenie, flota (majątek) i rejestr bitew wypraw.
 * Inwarianty: XP nigdy nie maleje (doświadczenia się nie traci, także po
 * porażce), każda bitwa jest rozliczana dokładnie raz, a flota nigdy nie
 * blokuje gry na zawsze — tratwa jest darmowa (bezpiecznik anty-softlock).
 */
final class CaptainProfile
{
    private const STARTING_MATERIALS = 20;

    /** @var array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>}> klucz: gameId */
    private array $battles = [];

    /** @var list<OwnedShip> */
    private array $fleet = [];

    private int $materials = 0;

    private function __construct(
        private ProfileId $id,
        private string $name,
        private int $xp = 0,
    ) {
    }

    public static function create(string $name): self
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 40) {
            throw new \InvalidArgumentException('Profile name must be 1-40 characters');
        }

        $self = new self(ProfileId::new(), $name);
        $self->materials = self::STARTING_MATERIALS;
        $self->fleet = self::startingFleet();

        return $self;
    }

    /**
     * Flota rozbitka: kuter + 3 tratwy — od pierwszej bitwy jest polowanie
     * (plansza samych jednomasztowców to czysta loteria).
     *
     * @return list<OwnedShip>
     */
    private static function startingFleet(): array
    {
        return [
            OwnedShip::build(ShipType::Kuter),
            OwnedShip::build(ShipType::Tratwa),
            OwnedShip::build(ShipType::Tratwa),
            OwnedShip::build(ShipType::Tratwa),
        ];
    }

    public function id(): ProfileId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function xp(): int
    {
        return $this->xp;
    }

    public function rank(): Rank
    {
        return Rank::fromXp($this->xp);
    }

    // --- Flota i materiały ---

    public function materials(): int
    {
        return $this->materials;
    }

    /** @return list<OwnedShip> */
    public function fleet(): array
    {
        return $this->fleet;
    }

    /** @return list<OwnedShip> statki zdolne do bitwy (nieuszkodzone) */
    public function activeFleet(): array
    {
        return array_values(array_filter($this->fleet, static fn (OwnedShip $s) => !$s->isDamaged()));
    }

    public function addMaterials(int $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Negative materials amount');
        }
        $this->materials += $amount;
    }

    /** Buduje statek: bramka rangi + koszt w materiałach (stocznię sprawdza warstwa aplikacji). */
    public function buildShip(ShipType $type): OwnedShip
    {
        if (!$this->rank()->atLeast($type->requiredRank())) {
            throw new \DomainException(sprintf('Ship type locked: requires rank %s', $type->requiredRank()->value));
        }
        $this->spendMaterials($type->buildCost());

        $ship = OwnedShip::build($type);
        $this->fleet[] = $ship;

        return $ship;
    }

    /** Remontuje uszkodzony statek za materiały. */
    public function repairShip(string $shipId): OwnedShip
    {
        $ship = $this->shipById($shipId);
        if (null === $ship) {
            throw new \DomainException('Ship not found');
        }
        if (!$ship->isDamaged()) {
            throw new \DomainException('Ship is not damaged');
        }

        $this->spendMaterials($ship->type->repairCost());
        $ship->repair();

        return $ship;
    }

    private function shipById(string $shipId): ?OwnedShip
    {
        foreach ($this->fleet as $ship) {
            if ($ship->id === $shipId) {
                return $ship;
            }
        }

        return null;
    }

    private function spendMaterials(int $cost): void
    {
        if ($this->materials < $cost) {
            throw new \DomainException('Not enough materials');
        }
        $this->materials -= $cost;
    }

    // --- Bitwy ---

    /**
     * Rejestruje rozpoczętą bitwę o wyspę (jedna gra = jedna bitwa).
     *
     * @param list<string> $shipIds statki, które wypłynęły (do rozliczenia strat)
     */
    public function startBattle(string $islandId, GameId $gameId, array $shipIds = []): void
    {
        $key = (string) $gameId;
        if (isset($this->battles[$key])) {
            throw new \DomainException('Battle already registered');
        }

        $this->battles[$key] = ['island' => $islandId, 'settled' => false, 'result' => null, 'ships' => $shipIds];
    }

    /** Wyspa, o którą toczy się dana gra; null gdy gra nie należy do profilu. */
    public function islandFor(GameId $gameId): ?string
    {
        return $this->battles[(string) $gameId]['island'] ?? null;
    }

    /**
     * Rozlicza bitwę: XP i materiały nigdy nie giną (przyznawane też za porażkę),
     * straty dotykają floty — zatopione statki po wygranej wymagają remontu,
     * po przegranej są stracone. Idempotentne: druga próba zwraca zera.
     *
     * @param list<int> $sunkLengths długości statków gracza zatopionych w bitwie
     *
     * @return array{xp:int, materials:int, lost:list<string>, damaged:list<string>}
     */
    public function settleBattle(GameId $gameId, string $result, int $xpAward, int $materialsAward = 0, array $sunkLengths = []): array
    {
        if (!in_array($result, ['won', 'lost'], true)) {
            throw new \InvalidArgumentException('Result must be won|lost');
        }
        if ($xpAward < 0 || $materialsAward < 0) {
            throw new \InvalidArgumentException('Negative battle award');
        }

        $key = (string) $gameId;
        $battle = $this->battles[$key] ?? throw new \DomainException('Battle not registered for this profile');
        if ($battle['settled']) {
            return ['xp' => 0, 'materials' => 0, 'lost' => [], 'damaged' => []];
        }

        $battle['settled'] = true;
        $battle['result'] = $result;
        $this->battles[$key] = $battle;

        $this->xp += $xpAward;
        $this->materials += $materialsAward;

        [$lost, $damaged] = $this->applyLosses($battle['ships'] ?? [], $result, $sunkLengths);

        return ['xp' => $xpAward, 'materials' => $materialsAward, 'lost' => $lost, 'damaged' => $damaged];
    }

    /**
     * Dobiera zatopione statki bitwy do egzemplarzy floty po długości
     * (bitwa zna tylko długości) i aplikuje straty.
     *
     * @param list<string> $battleShipIds
     * @param list<int>    $sunkLengths
     *
     * @return array{0: list<string>, 1: list<string>} [stracone typy, uszkodzone typy]
     */
    private function applyLosses(array $battleShipIds, string $result, array $sunkLengths): array
    {
        $lost = [];
        $damaged = [];
        $processed = [];

        foreach ($sunkLengths as $length) {
            foreach ($battleShipIds as $shipId) {
                if (isset($processed[$shipId])) {
                    continue;
                }
                $ship = $this->shipById($shipId);
                if (null === $ship || $ship->type->length() !== $length || $ship->isDamaged()) {
                    continue;
                }

                $processed[$shipId] = true;
                if ('lost' === $result) {
                    $this->fleet = array_values(array_filter($this->fleet, static fn (OwnedShip $s) => $s->id !== $shipId));
                    $lost[] = $ship->type->value;
                } else {
                    $ship->markDamaged();
                    $damaged[] = $ship->type->value;
                }
                break;
            }
        }

        return [$lost, $damaged];
    }

    /** @return array{wins:int, losses:int} rozliczone bitwy o daną wyspę */
    public function battleStats(string $islandId): array
    {
        $wins = 0;
        $losses = 0;
        foreach ($this->battles as $battle) {
            if ($battle['island'] !== $islandId || !$battle['settled']) {
                continue;
            }
            'won' === $battle['result'] ? ++$wins : ++$losses;
        }

        return ['wins' => $wins, 'losses' => $losses];
    }

    // --- Snapshot (mapper persystencji) ---

    /** @return array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>}> */
    public function battles(): array
    {
        return $this->battles;
    }

    /**
     * @param array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>}> $battles
     * @param list<OwnedShip>|null                                                                       $fleet     null = profil sprzed ekonomii — dostaje flotę startową
     * @param int|null                                                                                   $materials null = materiały startowe
     */
    public static function fromSnapshot(ProfileId $id, string $name, int $xp, array $battles, ?array $fleet = null, ?int $materials = null): self
    {
        $self = new self($id, $name, max(0, $xp));
        $self->battles = $battles;
        $self->fleet = $fleet ?? self::startingFleet();
        $self->materials = $materials ?? self::STARTING_MATERIALS;

        return $self;
    }
}
