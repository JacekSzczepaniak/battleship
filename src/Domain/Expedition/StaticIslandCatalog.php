<?php

namespace App\Domain\Expedition;

/**
 * Stały katalog wysp plastra A/B: liniowa trasa 6 wysp, kolejne bramkowane
 * rangą; wczesne wyspy mają mniejsze plansze (krótsze polowanie małą flotą).
 * Docelowo (plaster C) katalog będzie generowany z seeda świata.
 */
final class StaticIslandCatalog implements IslandCatalog
{
    /** @var list<Island>|null */
    private ?array $islands = null;

    public function all(): array
    {
        return $this->islands ??= [
            new Island(
                id: 'zatoka-rozbitka',
                name: 'Zatoka Rozbitka',
                description: 'Spokojne wody, na których uczysz się fachu. Ktoś tu jednak też ocalał — i nie chce się dzielić zatoką.',
                requiredRank: Rank::Rozbitek,
                mode: 'classic',
                xpWin: 40,
                xpLoss: 10,
                materialsWin: 20,
                materialsLoss: 5,
                shipyardLevel: 1,
                boardWidth: 7,
                boardHeight: 7,
            ),
            new Island(
                id: 'mielizny',
                name: 'Mielizny',
                description: 'Płytkie wody i wraki dawnych flot. Miejscowi znają każdą łachę piachu — Ty jeszcze nie.',
                requiredRank: Rank::Rozbitek,
                mode: 'classic',
                xpWin: 50,
                xpLoss: 12,
                materialsWin: 25,
                materialsLoss: 6,
                shipyardLevel: 1,
                boardWidth: 8,
                boardHeight: 8,
            ),
            new Island(
                id: 'wyspa-sygnalowa',
                name: 'Wyspa Sygnałowa',
                description: 'Stara stacja nasłuchowa wciąż działa. Tu po raz pierwszy usłyszysz ping sonaru — swój i cudzy.',
                requiredRank: Rank::Marynarz,
                mode: 'fun',
                xpWin: 70,
                xpLoss: 15,
                materialsWin: 35,
                materialsLoss: 8,
                shipyardLevel: 2,
                boardWidth: 9,
                boardHeight: 9,
            ),
            new Island(
                id: 'archipelag-mgiel',
                name: 'Archipelag Mgieł',
                description: 'Wyspy, które znikają z map. Walka w gęstej mgle wymaga zimnej krwi i pełnego arsenału.',
                requiredRank: Rank::Marynarz,
                mode: 'fun',
                xpWin: 80,
                xpLoss: 20,
                materialsWin: 40,
                materialsLoss: 10,
                shipyardLevel: 2,
                boardWidth: 10,
                boardHeight: 10,
            ),
            new Island(
                id: 'ciesnina-sztormow',
                name: 'Cieśnina Sztormów',
                description: 'Wąskie gardło szlaku handlowego. Kto trzyma cieśninę, trzyma całe morze — dlatego wszyscy o nią walczą.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 100,
                xpLoss: 25,
                materialsWin: 50,
                materialsLoss: 12,
                shipyardLevel: 3,
                boardWidth: 10,
                boardHeight: 10,
            ),
            new Island(
                id: 'twierdza-admiralicji',
                name: 'Twierdza Admiralicji',
                description: 'Ostatni bastion starej floty. Pokonaj garnizon twierdzy, a nikt nie odmówi Ci szarży admiralskiej.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 120,
                xpLoss: 30,
                materialsWin: 60,
                materialsLoss: 15,
                shipyardLevel: 3,
                boardWidth: 10,
                boardHeight: 10,
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
