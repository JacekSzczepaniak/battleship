# Handoff: Statki — UI flow (Port → Rozstawianie → Bitwa → Wynik)

## Overview
Statyczny projekt UI dla desktopowej (przeglądarka) gry hobbystycznej opartej na silniku gry w statki. Wizja: „od rozbitka na tratwie do admirała z lotniskowcem” (patrz `GAME_DESIGN.md` w repo gry). Pakiet obejmuje 4 ekrany pełnego flow pojedynczej bitwy: menu/port, rozstawianie floty, bitwę z broniami specjalnymi i energią, ekran wyniku z łupami i progresją rangi.

## About the Design Files
Pliki w tym pakiecie to **referencje projektowe w HTML** — pokazują docelowy wygląd i zachowanie, **nie są kodem produkcyjnym do skopiowania**. Zadanie: **odtworzyć te ekrany w istniejącym środowisku gry** (jej frameworku, wzorcach i bibliotekach). Jeśli warstwa UI jeszcze nie istnieje — wybrać najodpowiedniejszy framework (rekomendacja: React + TypeScript, Vite, CSS Modules lub vanilla-extract) i podpiąć go pod istniejący silnik (bitwa, profil kapitana, flota, seedy).

`Statki - UI Flow.dc.html` otwiera się bezpośrednio w przeglądarce (wymaga obok `support.js`). Wszystkie style są inline; dane plansz generuje klasa `Component` na dole pliku (`buildGrid`, `buildData`).

## Fidelity
**High-fidelity** dla layoutu, hierarchii, kolorów, typografii i copy. Dane (liczby, nazwy statków, układy trafień) są przykładowe — w implementacji pochodzą ze stanu gry. Brak logiki — interakcje opisane niżej są docelowe.

> Uwaga: do projektu dołączono później design system „Organic” (jasny, kremowo-terakotowy). Obecne ekrany go **nie używają** — są w ciemnym, morskim stylu opisanym w „Design Tokens”. Przed implementacją zdecyduj, który kierunek wizualny obowiązuje.

## Rama i nawigacja (wszystkie ekrany)
- Tło strony: `radial-gradient(1200px 700px at 70% -10%, #0e2a34 0%, #071319 60%)`, padding 36px 32px 80px.
- Nagłówek: kicker „PROJEKT UI · HOBBISTYCZNA GRA” (Plex Mono 600 11px, letter-spacing .32em, #5f8089, uppercase) + tytuł Space Grotesk 700 30px; legenda (trafienie / pudło / statek) po prawej.
- Lewa kolumna 132px, sticky (top 24px) — kafle nawigacji: padding 13px 12px, radius 12px, bg #0f2630, border 1px #1c3b46; numer Plex Mono 10px #5f8089, nazwa Space Grotesk 600 13px. *(Nawigacja jest elementem prezentacji projektu — w grze zastąpić routingiem między ekranami.)*
- Każdy ekran = ramka 1440×812, radius 18px, border 1px #1c3b46, bg `linear-gradient(160deg, #0b2129, #081820)`. Nad ramką etykieta sekcji (Plex Mono 600 11px, .2em, #e8843c) + linia 1px #1c3b46.

## Screens / Views

### 01 — Port macierzysty (menu główne)
**Cel:** start bitwy, przegląd profilu, floty i surowców.
**Layout:** grid `1fr 380px`.
- Dekoracja tła: siatka mapy 48px (linie #0f2c36, opacity .5); „radar” — koło 520px w prawym górnym rogu (offset −120px), dwa wewnętrzne pierścienie (inset 70px, 150px), border #143743, conic-gradient `#3fb6c433 → transparent 70deg` obracany 9s linear infinite.
- **Lewa kolumna** (padding 66px 60px, flex column):
  - kicker „AKWEN I · MORZE ROZBITKÓW” (Plex Mono 600 12px, .34em, #5f8089)
  - tytuł „STATKI.” Space Grotesk 700 92px, line-height .92, letter-spacing −.02em; kropka #3fb6c4
  - opis Archivo 400 18px/1.5, #a9c0c5, max-width 420px
  - akcje przy dole (`margin-top:auto`, max-width 440px, gap 14px):
    - primary „Nowa bitwa” + hint „ENTER”: padding 20px 24px, radius 14px, bg #e8843c, tekst #0a1a24, Space Grotesk 700 18px
    - siatka 2×2 secondary: „Kontynuuj rejs”, „Stocznia”, „Mapa akwenów”, „Ustawienia” — padding 17px 20px, radius 14px, bg #0d2530, border 1px #24505e, Space Grotesk 600 15px, wyrównanie do lewej
- **Prawa kolumna** (border-left #143743, bg #081a2199, padding 40px 34px, gap 22px):
  - avatar 56×56 radius 12px (bg #12303b, border #2b5866, inicjał #3fb6c4) + „Kpt. Vane” (700 20px) + „RANGA · MARYNARZ” (Plex Mono 11px, #e8843c, .18em)
  - pasek XP: etykieta „XP do KAPITANA … 1 240 / 2 000”; tor 8px radius 5px bg #0d2530; wypełnienie `linear-gradient(90deg,#3fb6c4,#59d0dd)` (62%)
  - statystyki 2 kolumny: „18 BITWY WYGRANE”, „47 ZATOPIONE” (liczba 700 22px)
  - surowce 3 kafle: kwadrat 14px radius 3px — drewno #8a6a3e, stal #7d8b93, złoto #d8ae4c; wartość 700 15px, etykieta Plex Mono 9px
  - lista floty „FLOTA · 5 JEDNOSTEK”: wiersz padding 11px 13px, radius 10px; ikona, nazwa (600 14px), podpis „nośnik · nalot/torpeda/sonar” (Plex Mono 10px #8fa6ab), mini-pasek kondycji 44×5px (pełny #3fb6c4, uszkodzony #d8ae4c)

### 02 — Rozstawianie floty
**Cel:** rozstawić jednostki na planszy przed bitwą.
**Layout:** padding 36px, grid `300px 1fr 300px`, gap 28px.
- **Lewa — taca statków:** nagłówek „Twoja flota” (700 22px) + instrukcja. Karty statków (padding 14px, radius 12px, bg #0d2530, border #24505e) z nazwą, statusem i podglądem długości (segmenty 22×12px, radius 3px, gap 4px):
  - Lotniskowiec 5, Niszczyciel 4 — „ROZSTAWIONY” (#3fb6c4), karta opacity .5
  - Krążownik 3 — **aktywny/ciągnięty**: bg #123039, border #e8843c, status „CIĄGNIJ ↻” #e8843c, segmenty #4b7a89
  - Kuter 3, Ścigacz 2 — „DO ROZSTAWIENIA” (#8fa6ab), cursor grab
- **Środek — plansza:** etykieta „TWOJE WODY · 12×12” + przełącznik rozmiaru 10² / **12²** / 14² (pigułki padding 6px 12px radius 8px; aktywna bg #123039, border/tekst #3fb6c4). Plansza w kontenerze padding 16px, radius 14px, bg #081a21, border #143743.
- **Prawa:** karta „ZASADY AKWENU” (statki nie mogą się stykać; nośniki → bronie; sztorm 20%), postęp „Rozstawiono 2 / 5” (pasek 7px), przyciski: „Losuj rozstawienie” (secondary) i „Do bitwy → (rozstaw wszystkie)” — **disabled**: bg #2c4a55, tekst #7d949a, `cursor:not-allowed`; po rozstawieniu wszystkich przyjmuje styl primary (#e8843c).

### 03 — Bitwa
**Cel:** tura gracza — wybór broni, strzał, opcjonalny ruch statku, koniec tury.
**Layout:** flex column: HUD / grid `1fr 1fr 320px` / pasek broni.
- **HUD** (padding 20px 32px, border-bottom #143743): „TURA 07 · Twój ruch” (Twój ruch #e8843c); separator 1×38px; **ENERGIA** — 3 segmenty 22×11px radius 3px (pełny #3fb6c4 + glow `0 0 8px #3fb6c477`, pusty bg #0d2530 border #24505e); po prawej surowce (pigułki padding 9px 13px).
- **Twoje wody:** nagłówek + przycisk „Przesuń flotę ⚡1” (padding 7px 12px, radius 8px, Plex Mono 11px); plansza z własnymi statkami, trafieniami i pudłami; stopka „wróg zadał 3 trafienia · Aurora uszkodzona”.
- **Wody wroga:** nagłówek #e8843c, licznik „zatopiono 1/4”; plansza w mgle (statki niewidoczne, tylko wyniki strzałów); stopka z podpowiedzią.
- **Dziennik** (bg #081a2199, padding 22px): wpisy padding 11px 13px radius 10px — nagłówek Plex Mono 10px (TY #e8843c, WRÓG #7d949a, SONAR #3fb6c4), treść Archivo 12px. Najnowszy wpis wyróżniony (bg #123039, border #3fb6c4). Na dole notka o ruchomych statkach (border 1px dashed #24505e).
- **Pasek broni** (padding 18px 28px, gap 14px): 4 karty flex:1 (padding 14px 16px, radius 14px) + „Zakończ turę” (primary, padding 0 30px):
  - Salwa — jedno pole — ⚡1
  - Sonar — skan 3×3 · z kutra — ⚡1
  - Torpeda — przebija linię · z niszczyciela — ⚡2 — **wybrana**: bg #1a2f30, border #e8843c, animacja pulsu glow 2.4s ease-in-out infinite (`0 0 18px #e8843c55` ↔ `0 0 26px #e8843c88`)
  - Nalot — **zablokowany** („lotniskowiec zatopiony”, podpis #c8624a), opacity .5, bg #0a1a22 — ⚡3

### 04 — Wynik bitwy
**Cel:** podsumowanie: XP, łupy, stan floty, dalsza akcja.
**Layout:** grid `1fr 460px`; poświata `radial-gradient(circle at 50% 22%, #e8843c22, transparent 45%)`.
- **Lewa** (padding 70px 60px): kicker #e8843c, „ZWYCIĘSTWO” Space Grotesk 700 84px/.95, opis (abordaż ostatniej jednostki). Postęp rangi „MARYNARZ → KAPITAN” +240 XP: tor 12px, stara część #2a5560 (62%), nowy przyrost gradient cyjan (12%) — w implementacji animować przyrost; „1 480 / 2 000 · 520 do awansu”. Chipy zatopionych (padding 9px 14px radius 9px); zdobyty abordażem wyróżniony cyjanem z „⚓ abordaż”. Przyciski: „Do portu” (primary), „Powtórz bitwę” (secondary), padding 18px 30px, radius 13px.
- **Prawa** (padding 44px 36px): **ŁUPY** siatka 2×2 — drewno +40, stal +15, złoto +60 (wartości 700 24px #3fb6c4), „MAPA AKWENU · Morze Mgieł” wyróżniona (border/tekst #e8843c). **STAN FLOTY** — karty z paskiem 6px: uszkodzony #e8843c (+ koszt remontu „25 drewna · 10 stali”), lekko #d8ae4c, nowy ⚓ #3fb6c4. Na dole „Napraw flotę w stoczni” (secondary, full width).
- Wariant **porażki** nie jest zaprojektowany — zgodnie z GDD: przegrana = utrata floty, brak łupów; XP nigdy nie maleje.
- **Implementacja odchodzi od projektu (#136)**, bo przy flocie ~30 statków wiersz na statek nie mieścił się na ekranie: nagłówek na całą szerokość (opis + postęp rangi), pod nim jeden pasek akcji, niżej dwie kolumny. Lewa: zatopione zgrupowane po typie („9× Tratwa”) i skutki bitwy jako lista z ikonami lucide. Prawa: łupy w rzędzie 4 kafelków z linią, gdzie trafiły (magazyn wyspy / ładownia), i stan floty po typie — utracone i uszkodzone (z łącznym kosztem remontu) na górze, sprawne w jednej linii.

## Plansza (komponent)
- 12×12 (konfigurowalna 10/12/14), komórka 34×34px, gap 3px, radius 4px. Kolumny A–L, wiersze 1–12 (Plex Mono 600 10px #6f8b93); kolumna etykiet 20px, wiersz etykiet 18px, gap 6px.
- Stany komórek:
  - woda: bg #0c2029, `inset 0 0 0 1px #163540`
  - mgła (plansza wroga): bg #0a1b23, `inset 0 0 0 1px #12303a`
  - statek: bg #33596a, `inset 0 0 0 1px #517f8f`
  - trafiony statek: bg #4a3327, inset #7a4a33 + kropka 15px #e8843c, glow `0 0 12px #e8843c99`
  - trafienie (mgła): kropka jak wyżej na tle mgły
  - zatopiony: bg #3a1c12, inset #7a3a22 + kropka 16px #e8843c
  - pudło: pierścień 7px, `inset 0 0 0 1.6px #5f8089`
- Docelowe hovery (niezaprojektowane szczegółowo): na planszy wroga podświetlenie obszaru wybranej broni (salwa 1 pole, sonar 3×3, torpeda linia, nalot wg WeaponSpec) — obrys #e8843c; podczas rozstawiania podgląd statku (poprawny #3fb6c4 / kolizja #c8624a).

## Interactions & Behavior
- Port: „Nowa bitwa” (Enter) → Rozstawianie. „Kontynuuj rejs” → wolne morze; „Stocznia”, „Mapa akwenów”, „Ustawienia” — ekrany poza zakresem.
- Rozstawianie: drag & drop z tacy; klik / R obraca; walidacja (granice, brak stykania); „Losuj” z seeda; „Do bitwy” aktywny dopiero przy komplecie.
- Bitwa: wybór broni (klik lub klawisze 1–4) → klik pola wroga → strzał, koszt energii. Broń niedostępna, gdy brak energii lub zatopiony nośnik. „Przesuń flotę” (⚡1): wybór własnego statku → przesunięcie o 1 pole, max raz na turę. „Zakończ turę” → ruch AI → wpisy w dzienniku → odnowienie energii.
- Gdy zostaje ostatni statek wroga — opcja **abordaż** zamiast dobicia (ryzyko porażki; mechanika otwarta w GDD).
- Wynik: animacja paska XP (~800ms ease-out), lista łupów; „Do portu” / „Powtórz bitwę” / „Napraw flotę w stoczni”.
- Telegrafowanie ryzyk (zasada GDD): koszty energii zawsze widoczne na kartach broni, sztorm pokazany przed bitwą.

## State Management
- `profile`: name, rank, xp, xpToNext, stats {wins, sunk}
- `resources`: {wood, steel, gold}
- `fleet[]`: {id, type, name, length, hp, maxHp, weapon?}
- `setup`: boardSize, placements[] {shipId, row, col, orientation}, selectedShipId
- `battle`: turn, activeSide, energy {current, max}, selectedWeapon, ownBoard, enemyBoard (komórki: unknown | miss | hit | sunk), moveUsedThisTurn, log[]
- `result`: outcome, xpGained, loot {wood, steel, gold, maps[]}, capturedShips[], fleetDamage[]
- Całość deterministyczna z seeda (bez globalnego RNG) — zgodnie z istniejącym silnikiem.

## Design Tokens
**Kolory**
- Tła: page #071319, gradient hi #0e2a34; ramka #0b2129 → #081820; panel #081a21 / #081a2199; karta #0d2530; karta aktywna #123039; nieaktywna #0a1a22; nav #0f2630
- Obramowania: #143743 (podziały), #1c3b46 (karty), #24505e (przyciski), #2b5866
- Tekst: primary #e8f0f0, secondary #c3d3d6, opis #a9c0c5, muted #8fa6ab, dim #7d949a, label #5f8089, osie planszy #6f8b93
- Akcent (akcja/trafienie): #e8843c, tekst na akcencie #0a1a24, jasny #e8b48a
- Info/sonar/postęp: #3fb6c4, jasny #59d0dd
- Ostrzeżenie: #d8ae4c; błąd/zablokowane: #c8624a; disabled bg #2c4a55
- Surowce: drewno #8a6a3e, stal #7d8b93, złoto #d8ae4c

**Typografia** (Google Fonts)
- Space Grotesk 500/600/700 — nagłówki, przyciski, liczby (92/84/30/24/22/20/18/16/15/14/13px)
- Archivo 400–700 — treść (18/17/13/12px)
- IBM Plex Mono 400–600 — etykiety, kickery, metadane (12/11/10/9px; uppercase, letter-spacing .14–.34em)

**Promienie:** 3–4px (segmenty, komórki), 8px (pigułki), 9–10px (wiersze), 11–12px (karty), 13–14px (przyciski), 18px (ramka ekranu), 50% (kropki, radar)
**Odstępy:** gapy 3, 6, 9–11, 14, 16, 18, 22, 26, 28px; paddingi paneli 22–70px
**Cienie:** wyłącznie glowy akcentu (`0 0 8–26px` z alfą akcentu); brak cieni elewacji

## Assets
Brak obrazów. Ikony jednostek/broni to tymczasowe znaki tekstowe (✈ ◆ ▮ ● ⚓ ⚡ ↻) — zastąpić spójnym zestawem ikon (np. Lucide) lub własnymi grafikami.

## Files
- `Statki - UI Flow.dc.html` — wszystkie 4 ekrany + generator plansz (klasa `Component` na dole pliku)
- `support.js` — runtime potrzebny tylko do podglądu HTML w przeglądarce
- `REJS.md` — handoff ekranu Rejs (`/expedition`): mapa akwenu zawsze widoczna, panel z zakładkami Wyspa / Flota / Badania / Dziennik, baner ostatniego zdarzenia (projekt z 23.10.2026)
- `Rejs.dc.html` — referencja HTML ekranu Rejs (otwiera się obok `support.js`)

## Ekran Rejs w grze (#111)
Wdrożony według `REJS.md`, z regułami gry ważniejszymi niż projekt (decyzja 23.10.2026):
- zasięg rejsu na mapie liczy się jak w grze — większa z różnic x i y (#98), nie odległość Manhattan,
- nazwy technologii zostają z gry (Pojemne ładownie, Mapy sztormowe…), opisy efektów zgodne z kodem,
- rynku w zakładce Wyspa nie ma (Epic J, #57), styl zostaje ciemny, morski — bez design systemu „Organic”.

Uzupełnienia ponad projekt: pierwsza zakładka (`#wyspa`) pokazuje miejsce floty — wyspę w porcie, bramę akwenu na jej sektorze albo przegląd akwenu na pełnym morzu (wyspy, magazyny, złoża, bramy z powodem blokady). Dziennik i baner czytają dziennik kapitana z backendu (`log` w `GET /profiles/{id}/expedition`, 50 ostatnich wpisów). Przeładunek ‹ › przenosi od razu — każde przeniesienie to 1 tik.
