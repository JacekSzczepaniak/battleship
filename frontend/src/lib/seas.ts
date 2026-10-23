// Akweny po stronie UI (Epic I): numer akwenu w nagłówku mapy (projekt REJS.md:
// „AKWEN I”). Kolejność jak w StaticSeaGraph; nowy akwen dopisuje się tutaj.

const SEA_NUMERALS: Record<string, string> = {
    'morze-rozbitkow': 'I',
    'ocean-sztormow': 'II',
    'morze-mgiel': 'III',
};

/** „AKWEN I”; akwen spoza listy dostaje sam napis. */
export function seaKicker(seaId: string): string {
    const numeral = SEA_NUMERALS[seaId];
    return numeral ? `Akwen ${numeral}` : 'Akwen';
}
