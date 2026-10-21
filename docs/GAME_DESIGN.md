# GAME_DESIGN.md — wizja gry (v2)

Żywy dokument — rama, nie specyfikacja. Decyzje kierunkowe oznaczamy ✅,
otwarte pytania trzymamy na końcu. Wersja 2 po burzach mózgów 2026-07-12/13;
uzupełnienie 2026-07-15 (planowanie): rozszerzona drabina rang, specjalizacje,
pobojowisko, warianty statków.

## Wizja jednym zdaniem

Od **rozbitka na tratwie** do **admirała z lotniskowcem**: podróż przez
łańcuch akwenów — na morzu odkrywanie, konwoje i bitwy; na wyspach wydobycie,
osady i drzewko technologiczne. Sercem gry pozostaje bitwa w statki.

```
morze:  ruch → odkrywanie → spotkania/bitwy → łupy i mapy
wyspa:  wydobycie → transport → stocznia/osada → technologia
        └── wszystko, co daje wyspa, wraca na morze ──┘
```

## Co już działa (zrealizowany „wstęp", stan po 2026-07-12)

Bitwa (classic/fun, bronie specjalne z nośników: sonar=kuter, torpeda=
niszczyciel, nalot=lotniskowiec; zatopiony nośnik odbiera broń) · profil
kapitana (XP nigdy nie maleje, rangi rozbitek→marynarz→kapitan→admirał) ·
flota jako majątek (budowa/remont, stocznie, wygrana→remont / przegrana→
strata) · wolne morze 12×12 z mgłą, deterministyczne z seeda profilu ·
kartografia pasywna · sztormy w niezbadanych wodach · geografia=logistyka
(bitwa/stocznia wymagają obecności). Szczegóły: git log + testy.

## Filar I — Świat: łańcuch akwenów ✅

Świat to **graf akwenów** (mórz), nie jedna wielka mapa. Każdy akwen =
osobna plansza (~12×12, z czasem większe) z własnym zestawem wysp,
generowana deterministycznie: `worldSeed → akwenSeed_i`.

- **Akwen 1 = „Morze Rozbitków"** — obecna trasa; istniejące profile
  niczego nie tracą.
- **Trudność rośnie w głąb** — silniejsze floty, droższe stocznie, lepsze
  łupy; progresja rang mapuje się na geografię.
- **Motyw akwenu = modyfikatory globalne, nie dekoracja** (2–3 parametry:
  szansa sztormu, promień widoczności, mnożniki cen/złóż), np.:
  Morze Rozbitków (spokojne, tutorial) → Archipelag Kupców (handel, piraci
  polują na konwoje) → Morze Mgieł (widoczność 0 — sonar i mapy w cenie) →
  Ocean Sztormów (sztorm 40%, najbogatsza stal) → Wody Admiralicji (endgame).

**Przejścia między akwenami — trzy rodzaje bram (mieszane):**
1. **Strzeżona cieśnina** — bitwa-boss: flota strażnicza + fort,
2. **Otwarty ocean** — wymaga technologii („Nawigacja oceaniczna"),
3. **Wody nieznane** — wymaga **mapy akwenu** (kupionej / z wraku / z questa).

Mapa akwenu = najcenniejszy przedmiot w grze; to domyka wątek „mapy jako
towar" i daje sens handlowi, questom i abordażom.

## Filar II — Czas: tik = akcja morska ✅

Czysta turowość, **zero zegara ściennego**. Tik = akcja na morzu
(rejs / bitwa / dokowanie). Budowa statku = N tików, kopalnia produkuje
X surowca/tik — świat żyje, gdy gracz działa. W pełni deterministyczne
(religia seedów obowiązuje), testowalne, bez mechanik f2p wymuszających
powroty. Odrzucone: czas rzeczywisty i hybrydy czasowe.

## Filar III — Wyspy: typologia i zdobywanie

**Typy wysp** (per akwen losowane z puli, deterministycznie):
- **Stoczniowa (1–2 na akwen)** — jedyne miejsce budowy dużych jednostek; hub
- **Surowcowa** — złoża do wydobycia; surowiec trzeba **odebrać i przewieźć**
- **Neutralna (handlowa)** — rynek (surowce, mapy, technologie), questy,
  tubylcy; tu się nie walczy
- **Osada gracza** — zdobyta wyspa rozwijana budynkami (tartak, kopalnia,
  uczelnia, fort)
- **Dzika/questowa** — wraki, ekspedycje, sekrety (czarna perła)

**Zdobywanie wyspy — trzy fazy:** ✅
1. potyczka morska (obecna bitwa = flota strażnicza),
2. **abordaż**: ostatni niezatopiony statek wroga można abordażować zamiast
   dobić → statek jako łup; ryzyko przegranej próby ✅,
3. **oblężenie fortu** — „battleship w innym smaku": plansza fortu (mury,
   działa nadbrzeżne, **magazyn prochu** = trafienie wywołuje eksplozję
   łańcuchową). Ta sama mechanika obsługuje bramy-cieśniny.

**Zdobyta wyspa przestaje być areną powtarzalnych bitew** — staje się
majątkiem. Nowe źródła walki: NPC, piraci, **kontrataki na osady**
(obrona = bitwa w odwróconych rolach; fort podnosi obronę).

## Filar IV — Ekonomia: trzy surowce, ładownia, konwoje ✅

- **Drewno** (→ kadłuby, budynki), **stal** (→ uzbrojenie, forty),
  **złoto** (→ handel, uczelnie, najemnicy). Obecne „materiały" stają się
  drewnem. Na start dokładnie trzy — budżet złożoności. ✅ Złoto wchodzi
  od razu (złoża na mapie), nawet jeśli jego sink (handel/uczelnie)
  pojawi się w późniejszym plastrze — migracja surowców tylko raz.
- **Ładownia**: statki mają pojemność cargo; surowce wozi się fizycznie
  między wyspami. Nowy typ: **transportowiec/galeon** — dużo cargo,
  bezbronny, wymaga eskorty.
- **Konwój = gameplay**: ryzyko (sztorm, pirat) skaluje się z wartością
  ładunku; ekonomia i walka sprzęgają się bez sztucznych mechanik.
- **Pobojowisko (pomysł na przyszłość):** po bitwie morskiej na miejscu
  starcia dryfują resztki z zatopionych statków (przynajmniej drewno).
  Zbiera je flota obecna na miejscu albo wysłane po nie transportowce pod
  eskortą — drugie zastosowanie transportowca poza konwojami, decyzja po
  bitwie: spalić tiki na zbieranie czy płynąć dalej. Bezpieczniki balansu:
  niski procent odzysku, resztki znikają po N tikach, po przegranej pole
  bitwy zostaje przy wrogu (wojna nie może się sama finansować).

## Filar V — Technologia: drzewko z dwiema ścieżkami

Gałęzie (szkic): **Kadłuby** (rozmiary, ładownia) · **Uzbrojenie**
(parametry broni — WeaponSpec już na to gotowy) · **Nawigacja** (ruch o 2
sektory, odporność na sztormy, jakość map, przejścia oceaniczne) ·
**Osady** (budynki, wydobycie). Surowce per gałąź (drewno/stal/złoto).

Każdy węzeł ma **dwie ścieżki odblokowania**:
- **standardową** — surowce + tiki + wymagana uczelnia (wolniej),
- **questową** — artefakt/wyczyn = natychmiast (Twoje „przyspieszenie").
Kilka węzłów-legend **tylko questowych** (czarna perła → „Nawigacja Legend").
Questy nie są obok gry — są skrótami i sekretami w drzewku.

**Warianty statków (kierunek):** ✅ technologia odblokowuje nie tylko
parametry, ale **warianty kadłubów z różnym wyposażeniem** — ten sam rozmiar,
inny loadout, np. trójmasztowiec torpedowy vs zwiadowczy (lepszy sonar
kosztem torped), lotniskowiec klasyczny (nalot) vs śmigłowcowy (zwalczanie
okrętów podwodnych). Balans przez wybór przy budowie: wariant to trade-off,
nie ulepszenie. Techniczna droga wejścia: WeaponSpec + koszt w stoczni.

## Filar VI — NPC i reputacja

Spotkania na morzu jak sztormy: deterministycznie z `seed+moveCount`.
- **Handlarz**: handel na miejscu (gorsze ceny niż port) / **napad** → łup,
  ale −reputacja u cechu → rosnące ceny → embargo → flota łowców nagród
  (nowy typ bitwy).
- **Pirat**: walka / ucieczka (koszt: tik albo część cargo) / okup.
- **Reputacja** = wybór stylu gry (kupiec ↔ korsarz); pole projektujemy
  od razu, mechaniki dowozimy później.

## Filar VII — Questy

- **Ekspedycja w głąb lądu** (dzikie wyspy): press-your-luck — idziesz
  głębiej po skarb czy wracasz z tym, co masz.
- Nagrody: mapy akwenów, artefakty (questowe ścieżki drzewka), statki,
  reputacja. Fabuła = krótkie teksty przy questach i bramach akwenów.

## Filar VIII — Kapitan: rangi i specjalizacje ✅

**Rozszerzona drabina rang** — obecne 4 stopnie to za mało na świat
z akwenami; celujemy w ~9, np.:
rozbitek → majtek → marynarz → bosman → sternik → porucznik → kapitan →
komandor → admirał.
Ranga nadal bramkuje typy statków i dostęp do wysp/akwenów — więcej stopni
= gładsza progresja i częstsze nagrody. XP bez zmian: nigdy nie maleje,
ranga wynika z XP (istniejące profile automatycznie dostają nową rangę).

**Specjalizacje — rozwój obok rangi:** ✅ dwie równoległe ścieżki,
rozwijane przez to, co gracz faktycznie robi (nie przez wybór z listy):
- **bojowa** — punkty z bitew (wygrane, zatopienia, abordaże),
- **ekonomiczna** — punkty z handlu, transportu, wydobycia, kartografii.
Poziom specjalizacji daje perki (szkic: bojowa → tańszy remont po wygranej,
dodatkowy strzał zwiadu; ekonomiczna → +ładownia, lepsze ceny, tańsza
budowa). Ścieżki nie wykluczają się — profil gracza wyłania się z akcji;
to wchłania pomysł „perków kapitanów" z poczekalni. Konkretne perki
i progi do zaprojektowania przy epiku.

## Zasady projektowe

1. **Wyspa produkuje dla morza** — każda mechanika lądowa musi oddawać
   coś do pętli morskiej; bitwa pozostaje sercem gry.
2. **Determinizm z seeda** — świat, sztormy, spotkania, łupy; zero
   globalnego RNG (port/seed jak `FleetGenerator`).
3. **Battleship w wielu smakach** — nowe tryby walki (fort, obrona osady)
   to warianty rdzennej mechaniki, nie osobne gry.
4. **Telegrafowanie > zaskoczenie** — ryzyka (sztorm, reputacja,
   kontratak) komunikowane zanim gracz podejmie decyzję.
5. **Budżet złożoności** — 3 surowce, 2–3 parametry na motyw akwenu,
   krótkie drzewko na start; głębia przez sprzężenia, nie przez liczbę bytów.
6. **Bezpiecznik anty-softlock** — tratwa darmowa, kartografia jako dochód
   bez ryzyka, żegluga bez wymogu sprawnej floty.
7. **Każdy plaster grywalny osobno** — kolejny etap zaczyna się od
   działającej gry.
8. **Bitwa rozwija się ze światem** — plastry ekonomii/świata nie zamrażają
   mechaniki bitwy; nowe bronie i warianty statków (przez WeaponSpec)
   wchodzą równolegle z rozwojem świata.

## Kierunek krojenia (szkic — zadania rozpiszemy przy planowaniu)

1. Trzy surowce + ładownia + transport (stocznie tylko na 1–2 wyspach)
2. Zdobywanie wysp (flaga, kontrataki)
3. Osady: budynki i produkcja per tik
4. Drzewko technologiczne
5. Fort (oblężenie) + abordaż
6. Akweny: graf świata, bramy, motywy
7. NPC, reputacja, questy-przyspieszenia

Kolejność do dyskusji — np. akweny mogą wejść wcześniej, jeśli ciasnota
Akwenu 1 zacznie uwierać.

## Otwarte pytania

- Budynki osady: konkretna lista i koszty (tartak/kopalnia/uczelnia/fort — co jeszcze?)
- Kształt drzewka: ile węzłów na start, które questowe?
- Rangi vs akweny: progi XP dla ~9 stopni; czy rosną z liczbą akwenów; gdzie osiągalny admirał?
- Specjalizacje: konkretne perki i progi; czy bojowa wpływa na samą bitwę, czy tylko na otoczkę (remont, zwiad)?
- Pobojowisko: procent odzysku, po ilu tikach resztki znikają?
- Kontrataki: częstość, siła, co się dzieje przy utracie osady?
- Ile akwenów na start (2–3?) i rozmiar planszy akwenu (12×12 wystarczy?)
- Balans z obserwacji: pełna utrata floty przy przegranej, sztormy 20%, koszty statków
- Abordaż: dokładna mechanika rozstrzygnięcia (rzut? mini-plansza? koszt?)

## Poczekalnia (pomysły wciąż aktualne, poza głównym torem)

Eventy losowe w bitwie (mgła, cisza morska, prąd) · frakcje jako skórki
(technologia/magia/rasa — flavor over mechanics) · miny · replay partii ·
statystyki/osiągnięcia · daily challenge · PvP (tylko na czystym rulesecie,
jeśli kiedykolwiek). Perki kapitanów przeniesione do Filaru VIII
(specjalizacje).
