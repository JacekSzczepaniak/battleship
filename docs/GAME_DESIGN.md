# GAME_DESIGN.md — wizja gry (v2)

Żywy dokument — rama, nie specyfikacja. Decyzje kierunkowe oznaczamy ✅,
otwarte pytania trzymamy na końcu. Wersja 2 po burzach mózgów 2026-07-12/13;
uzupełnienie 2026-07-15 (planowanie): rozszerzona drabina rang, specjalizacje,
pobojowisko, warianty statków; 2026-10-21: progi drabiny rang, stan „co działa”.

## Wizja jednym zdaniem

Od **rozbitka na tratwie** do **admirała z lotniskowcem**: podróż przez
łańcuch akwenów — na morzu odkrywanie, konwoje i bitwy; na wyspach wydobycie,
osady i drzewko technologiczne. Sercem gry pozostaje bitwa w statki.

```
morze:  ruch → odkrywanie → spotkania/bitwy → łupy i mapy
wyspa:  wydobycie → transport → stocznia/osada → technologia
        └── wszystko, co daje wyspa, wraca na morze ──┘
```

## Co już działa (zrealizowany „wstęp", stan po 2026-10-22)

Bitwa (classic/fun, bronie specjalne z nośników: sonar=kuter, torpeda=
niszczyciel, nalot=lotniskowiec, wyposażenie per statek; zatopiony nośnik
odbiera broń) · profil
kapitana (XP nigdy nie maleje, 9 rang od rozbitka do admirała — progi
w Filarze VIII; licznik zatopionych jednostek wroga) · flota jako majątek
(budowa/remont, stocznie, wygrana→remont / przegrana→strata; uszkodzony
statek wozi ładunek, ale nie walczy — #92) · rozbudowa
stoczni w osadzie (#82: Zatoka pod banderą 1→2) · fort w osadzie (#81:
nieobecność przy kontrataku to grabież połowy magazynu, wyspa zostaje) · wolne morze
12×12 z mgłą, deterministyczne z seeda profilu · kartografia pasywna ·
sztormy przy pierwszym wpłynięciu na sektor · geografia=logistyka (bitwa/stocznia
wymagają obecności) · bandery na wyspach i kontrataki z bitwą obronną; pełna
bandera daje obrońcy pierwszy strzał i odbiera napastnikowi największy statek
(Epic E) · drzewko technologii (Epic G: uczelnia, 8 technologii w 4
gałęziach, niszczyciel zwiadowczy jako pierwszy wariant statku) · UI flow
bitwy: port → rozstawianie → bitwa → wynik
(projekt: `docs/design/ui-flow/`). Elementy projektu UI bez pokrycia
w silniku czekają w zadaniach: energia i „Zakończ turę” (#59), ruch statku
w bitwie (#60), trzy surowce w UI (#49), abordaż (#55), mapa akwenu jako
łup (#56). Szczegóły: git log + testy, uruchomienie: `README.md`.

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

✅ (Epic I) Na start trzy akweny: Morze Rozbitków → (strzeżona cieśnina, od
rangi porucznik) → Ocean Sztormów → (otwarty ocean, technologia Nawigacja
oceaniczna) → Morze Mgieł. Motyw to trzy parametry: szansa sztormu,
widoczność i mnożnik złóż. Brama „wody nieznane” wchodzi z Epikiem J razem z
mapą akwenu.

✅ (#107) Akwen 2 „Ocean Sztormów” (sztorm 40%, widoczność 1, złoża ×2) i
strzeżona cieśnina. Sektor bramy leży na wschodniej krawędzi Morza Rozbitków,
a jego położenie widać po odkryciu, jak wyspy. Bitwę ze strażnikiem przyjmuje
flota stojąca na sektorze bramy, od rangi porucznik:
- plansza 12×12 z bronią specjalną,
- wróg ma stały skład: lotniskowiec, 2 niszczyciele, 2 kutry i 3 tratwy
  (17 pól), z domyślnym wyposażeniem, bez lustra składu gracza,
- gracz może wystawić do 20% pól planszy, jak przy wyspach,
- wygrana daje 200 XP oraz 40 drewna, 40 stali i 20 złota, a przegrana 50 XP
  i 10 drewna,
- łup trafia do ładowni, bo przy bramie nie ma magazynu, a nadmiar ponad
  pojemność przepada.
Wygrana otwiera bramę na stałe, w obie strony. Przejście kosztuje 1 tik, a
flota ląduje na sektorze bramy na zachodniej krawędzi Oceanu. Wpłynięcie
liczy się jak rejs: sztorm akwenu docelowego, podwójne straty konwoju bez
eskorty, kartografia. Na Oceanie jest 5 wysp: trzy od porucznika (Przystań
Wichrów ze stocznią poziomu 2, Rafa Kotwiczna ze stalą 3/tik, Wyspa Latarników
z drewnem 3/tik) i dwie od kapitana (Zatoka Burz ze stalą 2/tik i Bastion
Fal). Każda daje więcej XP i łupów niż Twierdza Admiralicji.

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
- **Osada gracza** ✅ (Epic F): budynki na posiadanej wyspie, każdy typ raz,
  bez poziomów, budowa natychmiastowa. Tartak i kopalnia przy złożu swojego
  surowca podwajają urobek. Fort chroni wyspę pod nieobecność floty: kontratak
  kończy się grabieżą połowy magazynu zamiast utraty wyspy. Rozbudowa stoczni
  podnosi istniejącą stocznię o poziom (Zatoka 1→2). Uczelnia prowadzi
  badania drzewka (filar V). Po utracie wyspy budynki stoją nieczynne do odbicia.
- **Dzika/questowa** — wraki, ekspedycje, sekrety (czarna perła)

✅ (#48) Morze Rozbitków: stocznie tylko na Zatoce Rozbitka (poziom 1)
i Wyspie Sygnałowej (poziom 3); złoża na Mieliznach (3 drewna/tik),
Archipelagu Mgieł (2 stali/tik) i Cieśninie Sztormów (1 złota/tik).
Złoże pracuje, dopóki wyspa jest pod banderą kapitana, urobek czeka w jej
magazynie do limitu 10 tików produkcji. Tratwę skleja się na każdej wyspie.

✅ (#82) Rozbudowa stoczni (50 drewna, 30 stali, 15 złota) to budynek osady:
podnosi stocznię poziomu 1–2 o jeden poziom, więc w Zatoce pod banderą
powstają niszczyciel i transportowiec. Bez bandery stocznia wraca do poziomu
z katalogu. Sygnałowa (poziom 3) i wyspy bez stoczni jej nie dostaną.

✅ (#81) Fort (40 drewna, 40 stali, 10 złota) to budynek osady na dowolnej
posiadanej wyspie poza Zatoką Rozbitka. Kontratak na wyspę z fortem, gdy do
uderzenia nikt nie rozpoczął obrony, kończy się grabieżą: połowa magazynu
przepada, a bandera, złoże i budynki zostają. Kontratak jest rozstrzygnięty,
więc 8 tików spokoju liczy się od uderzenia. Zapowiedź mówi, że fort obroni
wyspę przed utratą, a magazyn straci połowę. Gdy flota stoi na miejscu,
obrona jest zwykłą bitwą i przegrana dalej odbiera wyspę. Bez bandery fort
nie działa. Pełne oblężenie fortu z własną planszą to Epic H.

**Zdobywanie wyspy — trzy fazy:** ✅
1. potyczka morska (obecna bitwa = flota strażnicza),
2. **abordaż**: ostatni niezatopiony statek wroga można abordażować zamiast
   dobić → statek jako łup; ryzyko przegranej próby ✅,
3. **oblężenie fortu** — „battleship w innym smaku": plansza fortu (mury,
   działa nadbrzeżne, **magazyn prochu** = trafienie wywołuje eksplozję
   łańcuchową). Ta sama mechanika obsługuje bramy-cieśniny.

**Zdobyta wyspa przestaje być areną powtarzalnych bitew** ✅ (Epic E): pierwsza
wygrana stawia banderę. Posiadana wyspa ma pracujące złoże, a jej magazyn jest
stawką kontrataku. Zatoka Rozbitka to bezpieczna przystań: da się ją zdobyć,
ale nikt jej nie atakuje. Nowe źródła walki to NPC, piraci i **kontrataki**:
- najwyżej jeden naraz; po zdobyciu albo rozstrzygnięciu 8 tików spokoju,
  potem 15%/tik (seed+tik); cel to losowa posiadana wyspa poza Zatoką,
- zapowiedź 12 tików przed uderzeniem (największa odległość na mapie 12×12
  to 11 rejsów),
- obrona to bitwa w odwróconych rolach: flota musi stać na wyspie w oknie
  zapowiedzi, wróg ma lustrzany skład i strzela pierwszy, XP i łup jak przy
  zdobyciu; jedna obrona na kontratak, a rozpoczęta wstrzymuje termin do
  rozliczenia bitwy,
- przegrana obrona albo nieobecność oznacza utratę wyspy: bandera znika,
  złoże staje, połowa magazynu przepada, wyspa znowu jest areną; nieobecność
  na wyspie z fortem kończy się grabieżą połowy magazynu bez utraty wyspy (#81),
- pełna bandera na akwenie: obrońca strzela pierwszy, napastnik płynie bez
  największego statku; gdy lustrzany skład ma jeden statek, bonusem jest
  tylko pierwszy strzał. Bonus widać w zapowiedzi i znika z utratą
  którejkolwiek wyspy.

Obrona wyspy bez floty: fort w osadzie (#81, Filar III wyżej).

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
  ✅ (#47) Transportowiec: 5 pól, ładownia 60, bez broni; tratwa nie ma
  ładowni (darmowa ładownia = darmowy transport). Konwój = statki
  z transportowcem; eskorta = sprawny statek z bronią. Fale w sztormie
  zabierają 10% ładunku (min. 5 drewna), konwój bez eskorty — dwa razy
  tyle i nie atakuje wysp. Flota bez transportowca nie podlega regułom
  eskorty — bezpiecznik z zasady 6 zostaje.
  ✅ (#92) Pojemność ładowni = suma wszystkich statków floty, także
  uszkodzonych: uszkodzenie odbiera broń, nie ładownię. Uszkodzony statek
  pływa i wozi, ale nie walczy i nie eskortuje (uszkodzony transportowiec
  nadal tworzy konwój). Ładunek przepada tylko z utratą statku.
- **Pobojowisko (pomysł na przyszłość):** po bitwie morskiej na miejscu
  starcia dryfują resztki z zatopionych statków (przynajmniej drewno).
  Zbiera je flota obecna na miejscu albo wysłane po nie transportowce pod
  eskortą — drugie zastosowanie transportowca poza konwojami, decyzja po
  bitwie: spalić tiki na zbieranie czy płynąć dalej. Bezpieczniki balansu:
  niski procent odzysku, resztki znikają po N tikach, po przegranej pole
  bitwy zostaje przy wrogu (wojna nie może się sama finansować).

## Filar V — Technologia: drzewko z dwiema ścieżkami ✅

Gałęzie (szkic): **Kadłuby** (rozmiary, ładownia) · **Uzbrojenie**
(parametry broni — WeaponSpec już na to gotowy) · **Nawigacja** (ruch o 2
sektory, odporność na sztormy, jakość map, przejścia oceaniczne) ·
**Osady** (budynki, wydobycie). Surowce per gałąź (drewno/stal/złoto).

✅ (Epic G) Uczelnia na posiadanej wyspie, jedno badanie naraz, czas w tikach
pod banderą. Drzewko startowe: 4 gałęzie po 2 poziomy, drugi poziom od rangi
sternik. Technologie działają tylko po stronie gracza.

✅ (#96) Ekonomia: Pojemne ładownie (ładownia statku +50%, w dół) i Dok
remontowy (remont za pół ceny, w górę) w Kadłubach; Lepsze narzędzia (złoże
+1/tik, tartak i kopalnia podwajają powiększony urobek) w Osadach.

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

- ✅ (#99) **Niszczyciel zwiadowczy** (Uzbrojenie, poziom 2): po zbadaniu
  stocznia buduje niszczyciel w wariancie torpedowym (1 torpeda) albo
  zwiadowczym (2 sonary, bez torpedy). Koszt budowy i remontu jak niszczyciela,
  wariant jest stały. Sonar zwiadowcy ma promień sonaru gracza. Wariant to
  skład, a nie technologia, więc lustrzana flota wroga go przejmuje.
- ⏸️ **Lotniskowiec śmigłowcowy** czeka na okręty podwodne: bez nich nie ma
  czego zwalczać (Epic G, D5).

## Filar VI — NPC i reputacja

Spotkania na morzu jak sztormy: deterministycznie z `seed+tik`.
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

**Rozszerzona drabina rang** ✅ (2026-10-21) — 4 stopnie to za mało na
świat z akwenami, więc 9. Ranga bramkuje typy statków i dostęp do
wysp/akwenów — więcej stopni = gładsza progresja i częstsze nagrody. XP bez
zmian: nigdy nie maleje, ranga wynika z XP (nie jest zapisywana).

| ranga | próg XP | bramka dziś |
|---|---|---|
| rozbitek | 0 | tratwa, kuter, wyspy I poziomu |
| majtek | 30 | — |
| marynarz | 80 | niszczyciel, transportowiec, wyspy II poziomu |
| bosman | 150 | — |
| sternik | 250 | — |
| porucznik | 400 | — |
| kapitan | 600 | lotniskowiec, wyspy III poziomu |
| komandor | 900 | — |
| admirał | 1300 | — (endgame akwenów) |

- **Zysk XP do kolejnej rangi rośnie** (30 → 400): pierwsza wygrana daje
  od razu majtka, później awans co 1–4 wygrane; admirał po 15 wygranych
  (rachunek: same wygrane, zawsze najlepsza dostępna wyspa Akwenu 1;
  XP za porażki skraca drogę).
- **Bramki zostają przy nazwach rang** ✅ (wariant B): lotniskowiec i wyspy
  III poziomu wymagają kapitana, który wypada przy 600 XP zamiast 250.
  Skutek: profile z 250–599 XP spadły z kapitana na sternika/porucznika
  i do 600 XP nie zbudują lotniskowca ani nie zaatakują wysp III poziomu.
  Odrzucony wariant A przenosił bramkę na sternika (250 XP), żeby nikt nic
  nie tracił — kosztem nazwy „kapitan” bez znaczenia w grze.
- **Luka 250–600 XP:** na obecnym Akwenie 1 między sternikiem a kapitanem
  dostępne są tylko wyspy II poziomu (70–80 XP za wygraną, ok. 5 wygranych).
  Lukę zapełnia od #107 akwen 2: strażnik cieśniny od porucznika (400 XP)
  i trzy porucznikowskie wyspy Oceanu Sztormów.
- Rangi bez bramki (majtek, bosman, sternik, komandor, admirał) czekają na
  treść kolejnych akwenów. Porucznik od #107 otwiera strażnika cieśniny
  i wyspy Oceanu Sztormów.

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
6. **Bezpiecznik anty-softlock** — tratwa darmowa na każdej wyspie, kartografia jako dochód
   bez ryzyka, żegluga bez wymogu sprawnej floty. Od #47 kartografia płaci
   tylko do ładowni, więc flota z samych tratw jej nie zbiera — rozbitkowi
   zostają łupy z bitew (także porażek) w magazynie wyspy. Od #92 uszkodzone
   statki wożą ładunek, więc surowce na remont da się dowieźć do stoczni.
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

- Które technologie dostaną ścieżkę questową (Epic J) i czy dojdą technologie tylko questowe?
- Rangi vs akweny: czy progi rosną z liczbą akwenów; gdzie osiągalny admirał; czym zapełnić lukę 250–600 XP (dziś wyspy II poziomu, a od Epica G także drugi poziom drzewka) i które rangi bramkują akwen 2? Akwen 2 otwiera się od porucznika, a admirał to końcówka gry na akwenie 3.
- Specjalizacje: konkretne perki i progi; czy bojowa wpływa na samą bitwę, czy tylko na otoczkę (remont, zwiad)?
- Pobojowisko: procent odzysku, po ilu tikach resztki znikają?
- Balans z obserwacji: pełna utrata floty przy przegranej, sztormy 20%, koszty statków
- Abordaż: dokładna mechanika rozstrzygnięcia (rzut? mini-plansza? koszt?)
- Czy kiedyś dzielimy flotę na zespoły z własną pozycją?
- Budowa trwająca N tików (filar II): kiedy i czy jednocześnie dla statków i budynków?

## Poczekalnia (pomysły wciąż aktualne, poza głównym torem)

Eventy losowe w bitwie (mgła, cisza morska, prąd) · frakcje jako skórki
(technologia/magia/rasa — flavor over mechanics) · miny · replay partii ·
statystyki/osiągnięcia · daily challenge · PvP (tylko na czystym rulesecie,
jeśli kiedykolwiek). Perki kapitanów przeniesione do Filaru VIII
(specjalizacje).
