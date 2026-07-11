<?php

namespace App\Domain\Expedition;

/**
 * Stały katalog wysp plastra A: liniowa trasa 6 wysp, kolejne bramkowane rangą.
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
            ),
            new Island(
                id: 'mielizny',
                name: 'Mielizny',
                description: 'Płytkie wody i wraki dawnych flot. Miejscowi znają każdą łachę piachu — Ty jeszcze nie.',
                requiredRank: Rank::Rozbitek,
                mode: 'classic',
                xpWin: 50,
                xpLoss: 12,
            ),
            new Island(
                id: 'wyspa-sygnalowa',
                name: 'Wyspa Sygnałowa',
                description: 'Stara stacja nasłuchowa wciąż działa. Tu po raz pierwszy usłyszysz ping sonaru — swój i cudzy.',
                requiredRank: Rank::Marynarz,
                mode: 'fun',
                xpWin: 70,
                xpLoss: 15,
            ),
            new Island(
                id: 'archipelag-mgiel',
                name: 'Archipelag Mgieł',
                description: 'Wyspy, które znikają z map. Walka w gęstej mgle wymaga zimnej krwi i pełnego arsenału.',
                requiredRank: Rank::Marynarz,
                mode: 'fun',
                xpWin: 80,
                xpLoss: 20,
            ),
            new Island(
                id: 'ciesnina-sztormow',
                name: 'Cieśnina Sztormów',
                description: 'Wąskie gardło szlaku handlowego. Kto trzyma cieśninę, trzyma całe morze — dlatego wszyscy o nią walczą.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 100,
                xpLoss: 25,
            ),
            new Island(
                id: 'twierdza-admiralicji',
                name: 'Twierdza Admiralicji',
                description: 'Ostatni bastion starej floty. Pokonaj garnizon twierdzy, a nikt nie odmówi Ci szarży admiralskiej.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 120,
                xpLoss: 30,
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
