<?php

namespace App\Domain\Expedition;

/**
 * Katalog wysp Wód Czarnej Perły (Akwen 4, #143): tajny akwen za bramą
 * „wody nieznane”, do którego prowadzi tylko mapa. Trzy wyspy od rangi
 * admirał, czyli treść poza końcówką gry: łupy i XP najwyższe w grze,
 * a mnożnik ×3 z motywu akwenu dotyczy złóż, nie łupów. Stocznia na
 * Przystani Kaperskiej, złoże złota na Rafie Perłowej, fort na wraku.
 * Akwen nie ma portu handlowego — po handel wraca się na Morze Mgieł.
 */
final class BlackPearlIslandCatalog implements IslandCatalog
{
    /** @var list<Island>|null */
    private ?array $islands = null;

    public function all(): array
    {
        return $this->islands ??= [
            new Island(
                id: 'przystan-kaperska',
                name: 'Przystań Kaperska',
                description: 'Kaprowie Czarnej Perły remontują tu okręty bez pytania o banderę. Kto zdobędzie nabrzeże, ten dostanie ich doki.',
                requiredRank: Rank::Admiral,
                mode: 'fun',
                xpWin: 360,
                xpLoss: 90,
                lootWin: new Resources(wood: 40, steel: 40, gold: 36),
                lootLoss: new Resources(wood: 28),
                shipyardLevel: 3,
                boardWidth: 12,
                boardHeight: 12,
            ),
            new Island(
                id: 'rafa-perlowa',
                name: 'Rafa Perłowa',
                description: 'Pod płytką wodą rafy leżą perły, za które na Bazarze płaci się złotem. Załoga rafy pilnuje ich z armat.',
                requiredRank: Rank::Admiral,
                mode: 'fun',
                xpWin: 380,
                xpLoss: 95,
                lootWin: new Resources(wood: 40, steel: 42, gold: 40),
                lootLoss: new Resources(wood: 30),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(gold: 2),
            ),
            new Island(
                id: 'wrak-czarnej-perly',
                name: 'Wrak Czarnej Perły',
                description: 'Okręt-legenda osiadł na skałach i stał się twierdzą. W jego ładowni czeka skarb, o którym śpiewa się w każdym porcie.',
                requiredRank: Rank::Admiral,
                mode: 'fun',
                xpWin: 450,
                xpLoss: 110,
                lootWin: new Resources(wood: 50, steel: 50, gold: 50),
                lootLoss: new Resources(wood: 32),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                // fort: banderę daje dopiero wygrane oblężenie (#120)
                stronghold: true,
            ),
        ];
    }

    public function byId(string $id): ?Island
    {
        foreach ($this->all() as $island) {
            if ($island->id === $id) {
                return $island;
            }
        }

        return null;
    }
}
