<?php

namespace App\Domain\Expedition;

/**
 * Rodzaj wpisu w dzienniku kapitana (#111). Dziennik trzyma tylko zdarzenia,
 * które zmieniają sytuację gracza; rutynę (rejs bez sztormu, dokowanie,
 * kartografię) pokazuje sama mapa. Treść wpisu składa UI z kodu i danych.
 */
enum LogEvent: string
{
    /** Sztorm przy pierwszym wpłynięciu: fale zabrały ładunek albo uszkodziły statek. */
    case Storm = 'storm';

    /** Wygrana bitwa postawiła banderę na wyspie. */
    case IslandCaptured = 'island-captured';

    /** Wygrana potyczka na twierdzy: wyspa oblężona, banderę da wygrane oblężenie (#120). */
    case IslandBesieged = 'island-besieged';

    /** Przegrane oblężenie: wyspa zostaje oblężona, można ponowić szturm (#120). */
    case SiegeFailed = 'siege-failed';

    /** Kontratak odebrał wyspę: przegrana obrona albo uderzenie bez obrony. */
    case IslandLost = 'island-lost';

    /** Wygrana obrona: bandera została. */
    case IslandDefended = 'island-defended';

    /** Uderzenie bez obrony na wyspę z fortem: połowa magazynu przepadła, bandera została (#81). */
    case IslandPlundered = 'island-plundered';

    /** Zapowiedź kontrataku z tikiem uderzenia (#78). */
    case CounterattackAnnounced = 'counterattack-announced';

    /** Badanie ukończone — technologia działa od tego tiku. */
    case ResearchCompleted = 'research-completed';

    /** Stocznia zbudowała statek. */
    case ShipBuilt = 'ship-built';

    /** Na wyspie stanął budynek osady. */
    case BuildingBuilt = 'building-built';

    /** Wygrana ze strażnikiem otworzyła bramę akwenu na stałe (#107). */
    case GateOpened = 'gate-opened';

    /** Flota przepłynęła przez bramę na inny akwen. */
    case GatePassed = 'gate-passed';

    /** Udany abordaż: statek wroga czeka na decyzję kapitana (#118). */
    case ShipBoarded = 'ship-boarded';

    /** Kapitan zdecydował o zdobytym statku: dołączył go, rozebrał (#118) albo zbadał konstrukcję (#121). */
    case PrizeDecided = 'prize-decided';

    /** Kapitan zezłomował statek w stoczni; zwrot trafił do magazynu wyspy (#124). */
    case ShipScrapped = 'ship-scrapped';

    /** Transakcja na rynku portu handlowego: kupno albo sprzedaż drewna lub stali za złoto (#140). */
    case MarketTrade = 'market-trade';

    /** Kapitan sprzedał statek za złoto w porcie handlowym (#140). */
    case ShipSold = 'ship-sold';

    /** Kapitan zdobył mapę akwenu: kupioną na rynku albo z ekspedycji (#143, #144). */
    case ChartAcquired = 'chart-acquired';

    /** Spotkanie w rejsie: handlarz albo pirat czeka na decyzję (#141). */
    case EncounterMet = 'encounter-met';

    /** Spotkanie rozstrzygnięte bez bitwy: handel, minięcie, ucieczka albo okup (#141). */
    case EncounterResolved = 'encounter-resolved';

    /** Rozliczona bitwa spotkania: napad na handlarza albo walka z piratem (#141, pod reputację #142). */
    case EncounterBattle = 'encounter-battle';

    /** Kuter remontowy naprawił statek poza stocznią, za surowce z ładowni (#152). */
    case FieldRepair = 'field-repair';

    /** Udany powrót z ekspedycji w głąb dzikiej wyspy: nagrody w ładowni, po etapie 3 skarb (#144). */
    case InlandReturned = 'inland-returned';

    /** Porażka ekspedycji: zebrane nagrody przepadły, statek uszkodzony (#144). */
    case InlandFailed = 'inland-failed';

    /** Artefakt albo legenda z ekspedycji: technologia działa od razu (#144). */
    case ArtifactFound = 'artifact-found';
}
