// Nazwy i role jednostek po stronie UI. Broń wynika z wyposażenia statku; domyślne
// zależy od długości (Loadout::defaultFor): kuter(2)=sonar, niszczyciel(3)=torpeda,
// lotniskowiec(4)=nalot. Transportowiec(5) broni nie ma. Warianty statków (#99, #121)
// niosą inne wyposażenie, np. niszczyciel zwiadowczy 2 sonary zamiast torpedy.

import { SHIP_VARIANT_NAMES, type ShipTypeName, type ShipVariantName } from '../api/expeditionApi';
import type { Loadout } from '../api/gameApi';

export type WeaponKey = 'shot' | 'sonar' | 'torpedo' | 'airraid';

const DEFAULT_LOADOUT: Record<number, Loadout> = {
    2: { sonar: 1 },
    3: { torpedo: 1 },
    4: { airRaid: 1 },
};

/** Wyposażenie wariantu (ShipVariant::loadout) i nazwa statku w tym wariancie. */
export const VARIANTS: Record<ShipVariantName, { length: number; w: Loadout; name: string }> = {
    torpedowy: { length: 3, w: { torpedo: 1 }, name: SHIP_VARIANT_NAMES.torpedowy },
    zwiadowczy: { length: 3, w: { sonar: 2 }, name: SHIP_VARIANT_NAMES.zwiadowczy },
    // warianty ze zdobyczy (#121): wróg ma je w składzie, gracz po zbadaniu konstrukcji
    'kuter-torpedowy': { length: 2, w: { torpedo: 1 }, name: SHIP_VARIANT_NAMES['kuter-torpedowy'] },
    'ciezki-niszczyciel': { length: 3, w: { torpedo: 2 }, name: SHIP_VARIANT_NAMES['ciezki-niszczyciel'] },
    'lotniskowiec-eskortowy': { length: 4, w: { airRaid: 1, sonar: 1 }, name: SHIP_VARIANT_NAMES['lotniskowiec-eskortowy'] },
    // warsztat zamiast sonaru (#152): bez broni, naprawia flotę poza stocznią
    'kuter-remontowy': { length: 2, w: {}, name: SHIP_VARIANT_NAMES['kuter-remontowy'] },
};

const LOADOUT_WEAPON: Record<keyof Loadout, Exclude<WeaponKey, 'shot'>> = {
    sonar: 'sonar',
    torpedo: 'torpedo',
    airRaid: 'airraid',
};

const WEAPON_LABEL: Record<keyof Loadout, string> = {
    sonar: 'sonar',
    torpedo: 'torpeda',
    airRaid: 'nalot',
};

/** Wyposażenie statku: `w` z API albo domyślne dla długości. */
export function loadoutOf(length: number, w?: Loadout): Loadout {
    return w ?? DEFAULT_LOADOUT[length] ?? {};
}

/** Bronie, których statek jest nośnikiem. */
export function carriedWeapons(length: number, w?: Loadout): Exclude<WeaponKey, 'shot'>[] {
    return (Object.entries(loadoutOf(length, w)) as [keyof Loadout, number][])
        .filter(([, uses]) => uses > 0)
        .map(([weapon]) => LOADOUT_WEAPON[weapon]);
}

function sameLoadout(a: Loadout, b: Loadout): boolean {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)] as (keyof Loadout)[]);
    return [...keys].every(k => (a[k] ?? 0) === (b[k] ?? 0));
}

/** Wariant statku z planszy bitwy; null = wyposażenie domyślne albo statek bez wariantów. */
export function variantOf(length: number, w?: Loadout): ShipVariantName | null {
    if (!w) return null;
    const found = (Object.entries(VARIANTS) as [ShipVariantName, (typeof VARIANTS)[ShipVariantName]][])
        .find(([, v]) => v.length === length && sameLoadout(v.w, w));
    return found ? found[0] : null;
}

const NAME_BY_LENGTH: Record<number, string> = {
    1: 'Tratwa',
    2: 'Kuter',
    3: 'Niszczyciel',
    4: 'Lotniskowiec',
    5: 'Transportowiec',
};

const TYPE_BY_LENGTH: Record<number, ShipTypeName> = {
    1: 'tratwa',
    2: 'kuter',
    3: 'niszczyciel',
    4: 'lotniskowiec',
    5: 'transportowiec',
};

export function shipName(length: number, w?: Loadout): string {
    const variant = variantOf(length, w);
    if (variant) return VARIANTS[variant].name;
    return NAME_BY_LENGTH[length] ?? `${length}-masztowiec`;
}

export function shipTypeForLength(length: number): ShipTypeName | null {
    return TYPE_BY_LENGTH[length] ?? null;
}

/** Broń, którą jednostka daje flocie, np. „torpeda”, „sonar ×2” (tylko tryb z broniami specjalnymi). */
export function carriedWeapon(length: number, w?: Loadout): string | null {
    const parts = (Object.entries(loadoutOf(length, w)) as [keyof Loadout, number][])
        .filter(([, uses]) => uses > 0)
        .map(([weapon, uses]) => uses > 1 ? `${WEAPON_LABEL[weapon]} ×${uses}` : WEAPON_LABEL[weapon]);
    return parts.length ? parts.join(', ') : null;
}

/** „1 pole”, „3 pola”, „5 pól” — polska odmiana liczebnika. */
export function fieldsLabel(n: number): string {
    if (n === 1) return '1 pole';
    const last = n % 10;
    const lastTwo = n % 100;
    return last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14) ? `${n} pola` : `${n} pól`;
}

/** Oznaczenie pola jak na mapie: kolumna literą, wiersz liczbą (A1 = lewy górny róg). */
export function cellLabel(x: number, y: number): string {
    return `${columnLabel(x)}${y + 1}`;
}

export function columnLabel(x: number): string {
    return String.fromCharCode(65 + x);
}
