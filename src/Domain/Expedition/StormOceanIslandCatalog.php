<?php

namespace App\Domain\Expedition;

/**
 * Katalog wysp Oceanu Sztormów (Akwen 2, #107): 5 wysp za strzeżoną
 * cieśniną, z rangami od porucznika do kapitana. Łupy i XP wyższe niż na
 * wyspach kapitańskich Morza Rozbitków; mnożnik ×2 z motywu akwenu dotyczy
 * złóż, nie łupów. Stocznia tylko na Przystani Wichrów — surowce ze złóż
 * trzeba do niej dowieźć, jak w Akwenie 1.
 */
final class StormOceanIslandCatalog implements IslandCatalog
{
    /** @var list<Island>|null */
    private ?array $islands = null;

    public function all(): array
    {
        return $this->islands ??= [
            new Island(
                id: 'przystan-wichrow',
                name: 'Przystań Wichrów',
                description: 'Jedyny port po tej stronie cieśniny. Kto ma tu suche doki, ten remontuje flotę, zanim ocean ją dobije.',
                requiredRank: Rank::Porucznik,
                mode: 'fun',
                xpWin: 130,
                xpLoss: 32,
                lootWin: new Resources(wood: 25, steel: 24, gold: 18),
                lootLoss: new Resources(wood: 14),
                shipyardLevel: 2,
                boardWidth: 11,
                boardHeight: 11,
            ),
            new Island(
                id: 'rafa-kotwiczna',
                name: 'Rafa Kotwiczna',
                description: 'Na dnie leżą kotwice całych eskadr. Fale co tik wyrzucają na rafę stal z zatopionych kadłubów.',
                requiredRank: Rank::Porucznik,
                mode: 'fun',
                xpWin: 140,
                xpLoss: 35,
                lootWin: new Resources(wood: 25, steel: 26, gold: 19),
                lootLoss: new Resources(wood: 15),
                shipyardLevel: 0,
                boardWidth: 11,
                boardHeight: 11,
                depositYield: new Resources(steel: 3),
            ),
            new Island(
                id: 'wyspa-latarnikow',
                name: 'Wyspa Latarników',
                description: 'Latarnicy palą ogień na skraju sztormu i nie wpuszczą nikogo do swoich lasów bez walki.',
                requiredRank: Rank::Porucznik,
                mode: 'fun',
                xpWin: 140,
                xpLoss: 35,
                lootWin: new Resources(wood: 28, steel: 24, gold: 19),
                lootLoss: new Resources(wood: 15),
                shipyardLevel: 0,
                boardWidth: 11,
                boardHeight: 11,
                depositYield: new Resources(wood: 3),
            ),
            new Island(
                id: 'zatoka-burz',
                name: 'Zatoka Burz',
                description: 'Burza nie schodzi stąd od lat. Pod chmurami kryje się złoże stali — i flota, która go pilnuje.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 170,
                xpLoss: 42,
                lootWin: new Resources(wood: 28, steel: 30, gold: 22),
                lootLoss: new Resources(wood: 18),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(steel: 2),
            ),
            new Island(
                id: 'bastion-fal',
                name: 'Bastion Fal',
                description: 'Twierdza wykuta w skale na samym środku oceanu. Skarbiec bastionu to najlepszy łup po tej stronie cieśniny.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 200,
                xpLoss: 50,
                lootWin: new Resources(wood: 30, steel: 34, gold: 28),
                lootLoss: new Resources(wood: 20),
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
