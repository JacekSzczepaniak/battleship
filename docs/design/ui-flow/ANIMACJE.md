# Handoff: animacje — rejs floty i bronie

Uzupełnia `README.md` / `REJS.md` (te same tokeny kolorów). Referencja: `Animacje.dc.html` (otwórz w przeglądarce obok `support.js`; tweak `speed` 0,5–2×, `loop`). Kod animacji jest w klasie `Component` na dole pliku — metody `fleet`, `torp`, `raid`, `sonar`, `salvo` + helpery `hit`, `miss`, `shell`, `tile`. **To referencja, nie kod produkcyjny** — przenieś do warstwy UI gry.

## Technika
- Wyłącznie **Web Animations API** (`element.animate(keyframes, {duration, easing, fill})`) — zero bibliotek. Animowane tylko `transform` i `opacity` (+ `width` kilwateru, `strokeDashoffset` trasy).
- Efekty rysowane w **warstwie overlay** (`position:absolute; inset:0; pointer-events:none`) nad statyczną planszą. Efekty przejściowe usuwają się w `onfinish`; trwałe znaczniki (trafienie/pudło) zostają do czasu, aż stan gry przerysuje planszę.
- Siatka: komórka 40px, gap 4px → krok `P = 44`, środek pola `(c*P+20, r*P+20)`.
- Sekwencja = `async` funkcja z `await wait(ms)`; token anulowania przerywa sekwencję przy ponownym starcie / odmontowaniu. W grze: animacja ma **opóźniać wyświetlenie wyniku, nie go liczyć** — wynik strzału znany z silnika przed startem animacji (determinizm z seeda).
- `prefers-reduced-motion: reduce` → pomiń przeloty/łuki, pokaż od razu wynik z krótkim fade 150 ms.
- Wszystkie czasy dzielone przez mnożnik prędkości (opcja w ustawieniach gry: 1× / 1,5× / 2× / wył.).

## Wspólne efekty
**Trafienie** (`hit`): kafel #4a3327 / inset #7a4a33 + kropka 15px #e8843c fade-in 200 ms · błysk radialny 60px (#fff3e0→#f3a45f→#e8843c) scale .3→1.3, opacity 1→0, 520 ms ease-out · pierścień 3px #e8843c scale .4→2, 600 ms · 6 iskier 4px #f3a45f co 60° na 28px, 480 ms cubic-bezier(.1,.7,.3,1) · wstrząs planszy 260 ms (±3px).
**Pudło** (`miss`): kafel wody #0c2029 / inset #163540 + pierścień 8px (inset 1.6px #5f8089) fade 250 ms · rozprysk 28px, border 1.6px #8fb4bd, scale .3→1.5, 500 ms ease-out.
**Zatopienie**: kafle → #3a1c12 / inset #7a3a22, kropka 16px #c8624a, 400 ms · obrys całego statku 2px #e8843c radius 12px, scale 1.15→1, 400 ms · mocniejszy wstrząs 340 ms (±4px).

## 01 · Rejs floty A → B (ekran Rejs)
1. Trasa: SVG polyline przez środki pól, stroke #3fb6c4 2.5px, opacity .55, rysowanie `strokeDashoffset len→0` 700 ms ease-out.
2. Cel: okrąg 48px, 2px dashed #e8843c, scale 1.4→1 + obrót 90°, 500 ms.
3. Pauza ~850 ms, potem na każde pole (= 1 tik):
   - znacznik floty (26px #e8843c, glow) `translate` między środkami, 420 ms cubic-bezier(.45,0,.25,1)
   - ślad: kropka 8px #3fb6c4 na opuszczanym polu, opacity .9→0 + scale 1→.4, 1,4 s
   - po ~240 ms odsłonięcie mgły w promieniu 1 (Manhattan): opacity 1→0 + scale 1→.85, 380 ms
   - licznik TIK +1 po zakończeniu kroku
4. Dokowanie: cel znika 300 ms; 2× pierścień 2px #e8843c scale 1→2.4, 700 ms, odstęp 220 ms; komunikat „Zadokowano · <wyspa>”, przełączenie panelu na zakładkę Wyspa.
- Zdarzenie w trakcie rejsu (sztorm/spotkanie) → zatrzymaj na bieżącym polu i pokaż baner.

## 02 · Torpeda ⚡2 — przebija linię
1. Pas wiersza: border 1.5px dashed #e8843c, bg #e8843c14, radius 12px, fade-in 300 ms; pauza 600 ms.
2. Torpeda (24×6px #e8f0f0, glow + cyjan z tyłu) `translateX` od −34px do 8·P+10, **1,5 s linear**; kilwater 2px (gradient → #3fb6c4bb) rośnie `width` w tym samym czasie.
3. Wynik każdego pola odpalany w chwili, gdy torpeda mija środek pola: `t = (x_pola − x_start) / (x_end − x_start) · 1500 ms` → hit / miss.
4. Koniec: torpeda fade 200 ms, kilwater 600 ms, pas 400 ms.

## 03 · Nalot ⚡3 — obszar 3×3
1. Celownik: obszar 3×3, 2px dashed #e8843c, bg #e8843c10, radius 14px, scale 1.25→1, 350 ms cubic-bezier(.2,.8,.2,1); pauza 650 ms.
2. Przelot: wrapper obrócony 40° (kierunek lotu) z cieniem (owal 60×26 rgba(0,0,0,.55) blur 5px) + smugą 110×2px; translate po skosie przez planszę, 1,4 s linear.
3. Po 420 ms zrzuty co **90 ms** w kolejności przekątnej (r+c rosnąco): bomba 10px #e8f0f0 scale 2.2→.5 / opacity 0→1, 220 ms ease-in → hit / miss.
4. Błysk planszy #e8843c opacity 0→.12→0, 380 ms; celownik fade 500 ms.

## 04 · Sonar ⚡1 — skan 3×3
1. Obszar: 2px dashed #3fb6c4, bg #3fb6c40d, fade 300 ms.
2. 3 pierścienie 2px #3fb6c4 od środka, scale .2→3.6, 1,2 s cubic-bezier(.2,.6,.3,1), co 300 ms.
3. Odsłanianie wg odległości od środka (0,1,2), 110 ms na krok:
   - pole ze statkiem: #123c46, inset 2px #3fb6c4 + glow, 3× mrugnięcie w 900 ms
   - puste: #0f2f38, inset 1px #1f5c66, fade 250 ms
4. Sonar nie zdradza typu jednostki ani nie liczy się jako trafienie.

## 05 · Salwa ⚡1 — zwykły ostrzał
1. Celownik na polu: okrąg 44px 2px #e8843c + kropka 6px, scale 1.4→1, 220 ms ease-out.
2. Pocisk (8px #e8f0f0, glow) po **krzywej Béziera** z dolnej krawędzi planszy (174, 372) przez apex `min(sy, ty) − 70` do środka pola; 9 klatek kluczowych, 650 ms cubic-bezier(.3,0,.7,1); skala `1 + 0.8·sin(πt)` (złudzenie wysokości).
3. Cień pocisku: owal 12×6 blur 2px, liniowo po ziemi do celu, scale .4→1, opacity .15→.6.
4. Uderzenie → hit / miss; celownik fade 200 ms. Ostatnie pole statku → sekwencja **Zatopienie**.

## Integracja (propozycja)
- Moduł `fx/` z funkcjami `playMove(path)`, `playShot(cell, result)`, `playTorpedo(row, results[])`, `playAirstrike(cells, results[])`, `playSonar(center, detected[])` zwracającymi `Promise` → UI blokuje wejście do czasu rozwiązania, potem commit stanu.
- Ruch AI odtwarzany tymi samymi funkcjami na planszy gracza.
