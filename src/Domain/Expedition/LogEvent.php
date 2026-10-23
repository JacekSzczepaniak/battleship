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

    /** Kapitan zdecydował o zdobytym statku: dołączył go albo rozebrał (#118). */
    case PrizeDecided = 'prize-decided';
}
