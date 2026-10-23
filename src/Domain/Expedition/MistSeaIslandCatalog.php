<?php

namespace App\Domain\Expedition;

/**
 * Katalog wysp Morza Mgieł (Akwen 3, #108): 5 wysp za otwartym oceanem,
 * z rangami komandor i admirał — końcówka gry. Łupy i XP wyższe niż na
 * Oceanie Sztormów; mnożnik ×2 z motywu akwenu dotyczy złóż, nie łupów.
 * Stocznia tylko na Kotwicowisku Mgieł, od razu na najwyższym poziomie.
 */
final class MistSeaIslandCatalog implements IslandCatalog
{
    /** @var list<Island>|null */
    private ?array $islands = null;

    public function all(): array
    {
        return $this->islands ??= [
            new Island(
                id: 'kotwicowisko-mgiel',
                name: 'Kotwicowisko Mgieł',
                description: 'Kotwice leżą tu na dnie gęsto jak kamienie. Kto zdobędzie suche doki, zbuduje w nich wszystko, co pływa.',
                requiredRank: Rank::Komandor,
                mode: 'fun',
                xpWin: 220,
                xpLoss: 55,
                lootWin: new Resources(wood: 30, steel: 30, gold: 24),
                lootLoss: new Resources(wood: 20),
                shipyardLevel: 3,
                boardWidth: 12,
                boardHeight: 12,
            ),
            new Island(
                id: 'wyspa-szeptow',
                name: 'Wyspa Szeptów',
                description: 'We mgle słychać głosy, zanim widać brzeg. Pod szeptami kryje się żyła złota.',
                requiredRank: Rank::Komandor,
                mode: 'fun',
                xpWin: 240,
                xpLoss: 60,
                lootWin: new Resources(wood: 30, steel: 32, gold: 26),
                lootLoss: new Resources(wood: 22),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(gold: 2),
            ),
            new Island(
                id: 'mielizna-widm',
                name: 'Mielizna Widm',
                description: 'Na mieliźnie stoją wraki, które wyglądają jak żywe okręty. Między nimi rośnie las na budulec.',
                requiredRank: Rank::Komandor,
                mode: 'fun',
                xpWin: 240,
                xpLoss: 60,
                lootWin: new Resources(wood: 34, steel: 30, gold: 25),
                lootLoss: new Resources(wood: 22),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(wood: 4),
            ),
            new Island(
                id: 'archipelag-slepcow',
                name: 'Archipelag Ślepców',
                description: 'Wyspy tak gęsto otulone mgłą, że ich obrońcy strzelają na słuch. Trafiają zaskakująco często.',
                requiredRank: Rank::Admiral,
                mode: 'fun',
                xpWin: 290,
                xpLoss: 72,
                lootWin: new Resources(wood: 36, steel: 38, gold: 30),
                lootLoss: new Resources(wood: 25),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(steel: 3),
            ),
            new Island(
                id: 'cytadela-mgiel',
                name: 'Cytadela Mgieł',
                description: 'Twierdza, której nikt nie widział w całości. Jej skarbiec to największy łup na morzach.',
                requiredRank: Rank::Admiral,
                mode: 'fun',
                xpWin: 340,
                xpLoss: 85,
                lootWin: new Resources(wood: 42, steel: 44, gold: 38),
                lootLoss: new Resources(wood: 28),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
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
