<?php

namespace App\Domain\Expedition;

use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;

/**
 * Profil kapitana: doświadczenie i rejestr bitew wypraw. Inwarianty:
 * XP nigdy nie maleje (doświadczenia się nie traci, także po porażce),
 * a każda bitwa jest rozliczana dokładnie raz.
 */
final class CaptainProfile
{
    /** @var array<string, array{island: string, settled: bool, result: ?string}> klucz: gameId */
    private array $battles = [];

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

        return new self(ProfileId::new(), $name);
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

    /** Rejestruje rozpoczętą bitwę o wyspę (jedna gra = jedna bitwa). */
    public function startBattle(string $islandId, GameId $gameId): void
    {
        $key = (string) $gameId;
        if (isset($this->battles[$key])) {
            throw new \DomainException('Battle already registered');
        }

        $this->battles[$key] = ['island' => $islandId, 'settled' => false, 'result' => null];
    }

    /** Wyspa, o którą toczy się dana gra; null gdy gra nie należy do profilu. */
    public function islandFor(GameId $gameId): ?string
    {
        return $this->battles[(string) $gameId]['island'] ?? null;
    }

    /**
     * Rozlicza bitwę i przyznaje XP; zwraca przyznane XP (0 gdy już rozliczona —
     * rozliczenie jest idempotentne).
     */
    public function settleBattle(GameId $gameId, string $result, int $xpAward): int
    {
        if (!in_array($result, ['won', 'lost'], true)) {
            throw new \InvalidArgumentException('Result must be won|lost');
        }
        if ($xpAward < 0) {
            throw new \InvalidArgumentException('Negative XP award');
        }

        $key = (string) $gameId;
        $battle = $this->battles[$key] ?? throw new \DomainException('Battle not registered for this profile');
        if ($battle['settled']) {
            return 0;
        }

        $this->battles[$key] = ['island' => $battle['island'], 'settled' => true, 'result' => $result];
        $this->xp += $xpAward;

        return $xpAward;
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

    /** @return array<string, array{island: string, settled: bool, result: ?string}> */
    public function battles(): array
    {
        return $this->battles;
    }

    /** @param array<string, array{island: string, settled: bool, result: ?string}> $battles */
    public static function fromSnapshot(ProfileId $id, string $name, int $xp, array $battles): self
    {
        $self = new self($id, $name, max(0, $xp));
        $self->battles = $battles;

        return $self;
    }
}
