<?php

namespace App\Domain\Expedition;

/**
 * Akcja, za którą kapitan dostaje punkty specjalizacji (D3 Epica K).
 * Profil liczy surowe jednostki akcji (wygrane, złoto obrotu, przewiezione
 * jednostki), a punkty wychodzą z dzielenia, więc ułamki nie przepadają
 * między akcjami.
 */
enum SpecializationSource: string
{
    /** Wygrana bitwa wyprawy (każdego rodzaju). */
    case Win = 'win';

    /** Zatopiona jednostka wroga, także w przegranej bitwie. */
    case EnemySunk = 'enemy-sunk';

    /** Udany abordaż (premia obok wygranej). */
    case Boarding = 'boarding';

    /** Wygrana obrona wyspy przed kontratakiem (premia obok wygranej). */
    case Defense = 'defense';

    /** Wygrane oblężenie fortu (premia obok wygranej). */
    case Siege = 'siege';

    /** Złoto zapłacone albo otrzymane na rynku portu i u handlarza. */
    case MarketTurnover = 'market-turnover';

    /** Jednostki ładunku rozładowane na innej wyspie niż ta, na której je załadowano. */
    case Transport = 'transport';

    /** Jednostki urobku, które złoża odłożyły do magazynów wysp. */
    case Mining = 'mining';

    /** Nowo odkryty sektor morza. */
    case Discovery = 'discovery';

    public function track(): SpecializationTrack
    {
        return match ($this) {
            self::Win, self::EnemySunk, self::Boarding, self::Defense, self::Siege => SpecializationTrack::Combat,
            default => SpecializationTrack::Economy,
        };
    }

    /** Ile jednostek akcji składa się na jedną porcję punktów. */
    public function unitsPerPoint(): int
    {
        return match ($this) {
            self::MarketTurnover, self::Transport => 20,
            self::Mining => 30,
            default => 1,
        };
    }

    /** Punkty za każdą pełną porcję jednostek. */
    public function pointsPerUnit(): int
    {
        return match ($this) {
            self::Win, self::Boarding, self::Defense => 5,
            self::Siege => 10,
            default => 1,
        };
    }

    /** Punkty z danej liczby jednostek; reszta z dzielenia czeka na kolejne akcje. */
    public function points(int $units): int
    {
        return intdiv(max(0, $units), $this->unitsPerPoint()) * $this->pointsPerUnit();
    }
}
