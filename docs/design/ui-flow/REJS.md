# Handoff: ekran Rejs (`/expedition`) — porządkowanie UX

Uzupełnia `README.md` (te same tokeny: kolory, fonty, promienie). Plik referencyjny: `Rejs.dc.html` (otwiera się w przeglądarce obok `support.js`). To **referencja HTML, nie kod produkcyjny** — odtwórz w istniejącym kodzie gry (`127.0.0.1:5173/expedition`).

## Problem, który rozwiązuje
Po dodaniu nowych funkcji (osady, przeładunek, badania, flota 27 jednostek, dziennik) wszystko było na jednej długiej stronie pod mapą. Nowy układ: **mapa zawsze widoczna + jeden panel kontekstowy z zakładkami**. Gracz widzi naraz tylko to, czego potrzebuje do bieżącej decyzji.

## Struktura (z góry na dół)
1. **Pasek aplikacji**: logo „STATKI.” + nawigacja pigułkami Port / Bitwa / **Rejs** (aktywna: border #e8843c, bg #1a2f30).
2. **HUD** (jeden wiersz, `flex-wrap`; radius 16px, bg #0b2129, border #1c3b46, padding 16px 22px):
   - avatar 42px + nazwa + ranga (#e8843c, Plex Mono 10px) + „XP · najwyższa ranga”
   - 3 pigułki surowców (kwadrat 12px + liczba Space Grotesk 700 15px + etykieta)
   - **Ładownia floty**: etykieta „785 / 1041 · wolne 256” + pasek 8px **podzielony kolorami surowców** (drewno/stal/złoto proporcjonalnie do pojemności); `flex:1; min-width:200px`
   - **TIK** (aktualny licznik)
3. **Baner zdarzenia** (jedna linia, bg #123039, border #2b5866): kropka-sygnał, „TIK 833”, treść ostatniego ważnego zdarzenia, przycisk „Dziennik →” otwiera zakładkę Dziennik. Pokazuj tylko ostatnie zdarzenie; reszta w dzienniku.
4. **Główny obszar**: `display:flex; flex-wrap:wrap; gap:16px`
   - **Mapa** (`flex:none; max-width:100%; overflow-x:auto`) — przy wąskim oknie przewija się sama mapa, nie strona
   - **Panel kontekstowy** (`flex:1 1 380px; min-width:0`) — poniżej ~1060px przechodzi pod mapę

## Mapa akwenu
- Nagłówek: „AKWEN I” (kicker) + nazwa akwenu (Space Grotesk 700 24px); po prawej ostrzeżenie „sztorm w nieznanych: 10%” (bg #2a2418, border #5a4a28, tekst #d8ae4c) — **telegrafowanie ryzyka przed ruchem**.
- Siatka 12×12, komórka 42px, gap 4px, radius 8px; osie A–L / 1–12.
- Stany komórki:
  - zbadana: bg #0f2a34, inset 1px #1c3b46
  - niezbadana (mgła): bg #0a1b23, inset 1px #12303a
  - **w zasięgu rejsu**: bg #0f2f38, border 1.5px dashed #3fb6c4, cursor pointer, tooltip „Wypłyń · 1 tik”
  - wyspa: organiczny kształt 24×20px (`border-radius:50% 45% 50% 40%`), #c9a874, cień wewn. #a68756
  - bandera gracza: kwadrat 8px #e8843c w lewym górnym rogu
  - pozycja floty: obrys `0 0 0 2px #e8843c, 0 0 16px #e8843c88` (inset −3px)
  - tooltip (title) z nazwą wyspy / „tu jesteś”
- Zasięg = odległość Manhattan ≤ zasięgu żeglugi (1, lub 2 z „Żeglugą dwusektorową”).
- Legenda pod mapą + jedna linia instrukcji („Kliknij sektor w zasięgu… 1 tik za ruch”).

## Panel kontekstowy — zakładki
Pasek zakładek (bg #081a21, padding 8px, gap 4px); zakładka `flex:1`, padding 11px 10px, radius 10px; aktywna bg #123039 + inset 1px #2b5866, tekst #e8f0f0; nieaktywna transparent, #8fa6ab. Opcjonalny licznik-badge (pigułka, Plex Mono 10px).

Domyślna zakładka: **Wyspa**, gdy flota jest zadokowana; w przeciwnym razie Flota. (W pliku sterowane propem `defaultTab`).

1. **Wyspa „<nazwa>”** (etykieta zakładki = nazwa wyspy, gdy zadokowano)
   - tagi: „TWOJA OSADA” (bg #3a2516, border #7a4a2a, #e8b48a) + „SEKTOR E-2 · ZADOKOWANO”
   - nazwa wyspy 700 28px
   - **Przeładunek**: tabela `minmax(0,1fr) auto auto auto` — Surowiec | Wyspa | ← przenieś → | Statki. Kolumna środkowa: przycisk ‹ (na wyspę), pole liczbowe 48×30px, przycisk › (na statki). Nad tabelą „wolne w ładowni: 256”.
   - akcje zbiorcze: „Rozładuj wszystko na wyspę” (primary), „Załaduj ile się zmieści” (secondary)
   - podpowiedź (border dashed): stan magazynu + sugestia (np. budowa fortu)
   - Do dodania analogicznie w tej zakładce, gdy wyspa ma: budynki, stocznię, rynek. Sekcje w kolejności: przeładunek → budynki → stocznia/rynek.
2. **Flota** (badge = liczba jednostek): nagłówek „27 jednostek” + tag stanu („WSZYSTKIE SPRAWNE” lub liczba uszkodzonych). Lista **zgrupowana po typie**: `48px 1fr auto` — liczba „10×”, nazwa + rola (np. „nośnik · torpeda”), podgląd długości segmentami 12×8px. Nie pokazujemy 27 osobnych wierszy.
3. **Badania** (badge „8/8”): opis zasad (jedno badanie naraz, koszt z magazynu wyspy z uczelnią + ładownia, czas w tikach). Poziomy: nagłówek + koszt w jednej linii, węzły w siatce 2 kolumn (nazwa, opis efektu, znacznik ✓ gdy ukończone). Stany węzła do zaimplementowania: zablokowany (opacity .5), dostępny (border #24505e + przycisk „Badaj”), w toku (pasek tików), ukończony (✓ #3fb6c4).
4. **Dziennik**: lista `72px 1fr` — „TIK 835” (Plex Mono 11px #5f8089) + treść; najnowsze na górze.

## Zachowanie
- Klik sektora w zasięgu → rejs, +1 tik, aktualizacja HUD, ewentualny wpis/baner (sztorm, spotkanie).
- Dokowanie przy wyspie automatycznie przełącza panel na zakładkę Wyspa.
- Przeładunek: ‹/› przenoszą wartość z pola (puste = 1); walidacja względem wolnej ładowni i zapasów; liczby w tabeli i pasku ładowni aktualizują się od razu.
- Stan aktywnej zakładki trzymaj w URL hash (`#wyspa`, `#flota`, `#badania`, `#dziennik`), żeby przeładowanie strony go zachowało.
- Responsywność: panel przechodzi pod mapę < ~1060px; mapa ma własny poziomy scroll.

## Stan (propozycja)
- `ui.activeTab`, `ui.lastEvent`
- `expedition`: akwenId, position, reach, tick, explored:Set, islands[] {id, name, sector, owner, storage{wood,steel,gold}, buildings[]}
- `fleet.groups[]` {type, count, role, length, hpStatus}, `fleet.cargo{wood,steel,gold}`, `fleet.capacity`
- `research`: levels[] → nodes[] {id, name, desc, status}, active?
- `log[]` {tick, text}

## Uwaga o stylu
Do projektu dołączono design system **Organic** (jasny, kremowo-terakotowy). Ten ekran, tak jak cały flow, jest w ciemnym, morskim stylu z `README.md`. Jeśli przechodzisz na Organic, zmienia się tylko warstwa wizualna; układ i zachowanie opisane wyżej zostają.
