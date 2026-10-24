// Geometria planszy dla efektów (#145): liczona z układu na stronie, bo komórka
// ma 27–34 px zależnie od rozmiaru planszy (projekt zakładał stałe 40 px + 4 px).

export interface Point { x: number; y: number }
export interface CellRect { left: number; top: number; size: number }
export type Cell = [number, number];

export interface FxBoard {
    /** Warstwa overlay nad komórkami (position:absolute; inset:0). */
    layer: HTMLElement;
    /** Element, którym trzęsie wstrząs po trafieniu (komórki + overlay). */
    shaker: HTMLElement;
    cols: number;
    rows: number;
    /** Bok komórki i krok siatki (bok + odstęp) w px. */
    size: number;
    pitch: number;
    /** Wymiary siatki w px. */
    width: number;
    height: number;
    /** Skala efektów względem komórki 40 px z projektu. */
    k: number;
    rect(x: number, y: number): CellRect;
    center(x: number, y: number): Point;
    /** Usuwa trwałe znaczniki i przerywa wstrząs — po przerysowaniu stanu gry. */
    clear(): void;
}

/**
 * Plansza z elementu siatki komórek (dzieci w kolejności wierszami) i warstwy
 * overlay leżącej dokładnie na tej siatce. Offsety komórek są liczone względem
 * wspólnego, pozycjonowanego rodzica siatki i warstwy.
 */
export function boardFromGrid(cells: HTMLElement, layer: HTMLElement, shaker: HTMLElement, cols: number, rows: number): FxBoard | null {
    const first = cells.children[0] as HTMLElement | undefined;
    if (!first || cols === 0 || rows === 0) return null;
    const second = cols > 1 ? cells.children[1] as HTMLElement : null;
    const size = first.offsetWidth;
    const pitch = second ? second.offsetLeft - first.offsetLeft : size + 3;

    const rect = (x: number, y: number): CellRect => {
        const el = cells.children[y * cols + x] as HTMLElement | undefined;
        if (el) return { left: el.offsetLeft, top: el.offsetTop, size };
        // poza planszą (start torpedy, przelot): ekstrapolacja z siatki
        return { left: first.offsetLeft + x * pitch, top: first.offsetTop + y * pitch, size };
    };

    return {
        layer,
        shaker,
        cols,
        rows,
        size,
        pitch,
        width: cells.offsetWidth,
        height: cells.offsetHeight,
        k: size / 40,
        rect,
        center(x, y) {
            const r = rect(x, y);
            return { x: r.left + size / 2, y: r.top + size / 2 };
        },
        clear() {
            layer.replaceChildren();
            shaker.getAnimations().forEach(a => a.cancel());
        },
    };
}
