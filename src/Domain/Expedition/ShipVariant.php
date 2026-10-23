<?php

namespace App\Domain\Expedition;

use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\WeaponKind;

/**
 * Wariant statku (filar V): ten sam kadłub, inne wyposażenie w bitwie.
 * Wybiera się go przy budowie i jest stały — inny wariant to nowy statek.
 * Koszt budowy i remontu jest kosztem typu. Niszczyciel ma wariant domyślny
 * (torpedowy, #99); kuter i lotniskowiec bez wariantu (null) to statek
 * podstawowy z wyposażeniem wynikającym z długości.
 *
 * Warianty „ze zdobyczy” (#121) wróg ma w składzie na swoim akwenie; gracz
 * odblokowuje je, badając konstrukcję zdobytego statku (TechUnlock::Boarding).
 */
enum ShipVariant: string
{
    /** 1 torpeda — niszczyciel sprzed wariantów i domyślny przy budowie. */
    case Torpedowy = 'torpedowy';

    /** 2 sonary, bez torpedy; promień sonaru jest promieniem gracza (#97). */
    case Zwiadowczy = 'zwiadowczy';

    /** Kuter z torpedą zamiast sonaru (Ocean Sztormów, #121). */
    case KuterTorpedowy = 'kuter-torpedowy';

    /** Niszczyciel z 2 torpedami (Ocean Sztormów, #121). */
    case CiezkiNiszczyciel = 'ciezki-niszczyciel';

    /** Lotniskowiec z nalotem i sonarem (Morze Mgieł, #121). */
    case LotniskowiecEskortowy = 'lotniskowiec-eskortowy';

    /** Typ, którego to wariant. */
    public function type(): ShipType
    {
        return match ($this) {
            self::Torpedowy, self::Zwiadowczy, self::CiezkiNiszczyciel => ShipType::Niszczyciel,
            self::KuterTorpedowy => ShipType::Kuter,
            self::LotniskowiecEskortowy => ShipType::Lotniskowiec,
        };
    }

    /** Wariant, który dostaje statek budowany bez wyboru i niszczyciel ze starego snapshotu. */
    public static function defaultFor(ShipType $type): ?self
    {
        return ShipType::Niszczyciel === $type ? self::Torpedowy : null;
    }

    /** @return list<self> warianty typu (pusta lista = typ bez wariantów) */
    public static function forType(ShipType $type): array
    {
        return array_values(array_filter(self::cases(), static fn (self $v) => $v->type() === $type));
    }

    /** Technologia odblokowująca budowę wariantu; null = dostępny zawsze. */
    public function requiredTechnology(): ?Technology
    {
        return match ($this) {
            self::Torpedowy => null,
            self::Zwiadowczy => Technology::NiszczycielZwiadowczy,
            self::KuterTorpedowy => Technology::KuterTorpedowy,
            self::CiezkiNiszczyciel => Technology::CiezkiNiszczyciel,
            self::LotniskowiecEskortowy => Technology::LotniskowiecEskortowy,
        };
    }

    /** Wyposażenie w bitwie. Wariant domyślny niesie wyposażenie wynikające z długości. */
    public function loadout(): Loadout
    {
        return match ($this) {
            self::Torpedowy => Loadout::defaultFor($this->type()->length()),
            self::Zwiadowczy => Loadout::of(WeaponKind::Sonar, 2),
            self::KuterTorpedowy => Loadout::of(WeaponKind::Torpedo),
            self::CiezkiNiszczyciel => Loadout::of(WeaponKind::Torpedo, 2),
            self::LotniskowiecEskortowy => Loadout::fromArray([WeaponKind::AirRaid->value => 1, WeaponKind::Sonar->value => 1]),
        };
    }

    /** Wariant statku z planszy bitwy po długości i wyposażeniu; null = statek bez wariantów. */
    public static function ofBattleShip(int $length, Loadout $loadout): ?self
    {
        foreach (self::cases() as $variant) {
            if ($variant->type()->length() === $length && $variant->loadout()->equals($loadout)) {
                return $variant;
            }
        }

        return null;
    }
}
