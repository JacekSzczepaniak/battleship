// Nazwy i role jednostek po stronie UI. Silnik zna statki po długości, a broń
// wynika ze składu floty (Game::*_CARRIER_LENGTH): kuter(2)=sonar,
// niszczyciel(3)=torpeda, lotniskowiec(4)=nalot. Transportowiec(5) broni nie ma.

import type { ShipTypeName } from '../api/expeditionApi';

export type WeaponKey = 'shot' | 'sonar' | 'torpedo' | 'airraid';

export const CARRIER_LENGTH: Record<Exclude<WeaponKey, 'shot'>, number> = {
    sonar: 2,
    torpedo: 3,
    airraid: 4,
};

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

export function shipName(length: number): string {
    return NAME_BY_LENGTH[length] ?? `${length}-masztowiec`;
}

export function shipTypeForLength(length: number): ShipTypeName | null {
    return TYPE_BY_LENGTH[length] ?? null;
}

/** Broń, którą jednostka danej długości daje flocie (tylko tryb z broniami specjalnymi). */
export function carriedWeapon(length: number): string | null {
    switch (length) {
        case CARRIER_LENGTH.sonar: return 'sonar';
        case CARRIER_LENGTH.torpedo: return 'torpeda';
        case CARRIER_LENGTH.airraid: return 'nalot';
        default: return null;
    }
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
