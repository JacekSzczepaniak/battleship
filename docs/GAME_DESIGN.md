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
odbiera broń; w trybie fun energia 3⚡ na turę i „Zakończ turę”, #59, oraz ruch
statku o 1 pole, #60) · profil
kapitana (XP nigdy nie maleje, 9 rang od rozbitka do admirała — progi
w Filarze VIII; licznik zatopionych jednostek wroga; specjalizacje bojowa
i ekonomiczna z akcji, 5 poziomów z katalogiem perków — #160) · flota jako majątek
(budowa/remont, stocznie, wygrana→remont / przegrana→strata; uszkodzony
statek wozi ładunek, ale nie walczy — #92; złomowanie w stoczni za połowę
kosztu budowy do magazynu wyspy, uszkodzony mniej o remont, bez ostatniej
sprawnej jednostki — #124) · port handlowy z rynkiem na akwenach
1–3, sprzedaż statku za złoto (#140) · rozbudowa
stoczni w osadzie (#82: Zatoka pod banderą 1→2) · fort w osadzie (#81:
nieobecność przy kontrataku to grabież połowy magazynu, wyspa zostaje) · wolne morze
12×12 z mgłą, deterministyczne z seeda profilu · kartografia pasywna ·
sztormy przy pierwszym wpłynięciu na sektor · geografia=logistyka (bitwa/stocznia
wymagają obecności) · bandery na wyspach i kontrataki z bitwą obronną; pełna
bandera daje obrońcy pierwszy strzał i odbiera napastnikowi największy statek
(Epic E) · drzewko technologii (Epic G: uczelnia, 9 technologii w 4
gałęziach na 3 poziomach, niszczyciel zwiadowczy jako pierwszy wariant
statku; 3 warianty tylko z abordażu, #121) · cztery akweny połączone trzema typami bram: strzeżoną
cieśniną, otwartym oceanem i wodami nieznanymi z mapą akwenu (#106–#108, #143) · UI flow
bitwy: port → rozstawianie → bitwa → wynik oraz ekran Rejs z mapą zawsze
widoczną, panelem z zakładkami i dziennikiem kapitana (#111) · zapisane
zestawy floty z rozmieszczeniem (#125, sekcja niżej)
(projekt: `docs/design/ui-flow/`). Elementy projektu UI bez pokrycia
w silniku czekają w zadaniach: trzy surowce w UI (#49), abordaż (#55). Mapy akwenu
nie ma w łupach z bitew (#56 rozstrzygnięte w #143). Szczegóły: git log + testy, uruchomienie: `README.md`.

## Rdzeń — bitwa: energia i tura ✅

Tryb z bronią specjalną (fun: trening z bronią i każda bitwa wyprawy —
wyspa, obrona, strażnik cieśniny) ma **energię w turze** (#59, wariant B:
jeden atak na turę, energia na działania wspierające). Tryb klasyczny jest
bez energii: jedna salwa, potem ruch AI.

- **Pula:** 3⚡ na turę, ta sama dla obu stron, odnawiana, gdy strona
  dostaje turę (energia gracza wraca po ruchu przeciwnika). Pula jest
  stała; wzrost z rangą albo technologią odłożony do Epica K (#58).
- **Koszty:** salwa 1, sonar 1, torpeda 2, nalot 3, ruch statku 1.
  Koszt widać na karcie broni, a bez energii działanie jest odrzucane.
- **Atak kończy turę:** salwa, torpeda i nalot oddają turę. Sonar i ruch
  statku tylko zużywają energię, więc można skanować kilka razy w turze,
  póki starcza energii i limitu użyć. Przykładowe tury: sonar + sonar +
  salwa, sonar + torpeda, sam nalot.
- **„Zakończ turę”** oddaje turę bez ataku, np. po samych skanach.
- **Limity użyć na bitwę zostają** (liczba nośników we flocie); energia jest
  limitem na turę i działa obok nich.
- **AI płaci tą samą energią:** najpierw sonar, gdy ma sens, potem jeden
  atak, na który go jeszcze stać. Po sonarze zostają 2⚡, więc nalot AI
  wypada tylko w turze bez skanu.

### Ruch statku ✅

„Przesuń flotę ⚡1” (#60, decyzje z 23.10.2026) — tylko w trybie z energią.

- **Koszt i limit:** 1⚡, najwyżej raz na turę; ruch nie kończy tury.
- **Kierunek:** o 1 pole wzdłuż osi statku, do przodu albo do tyłu; tratwa
  (1 pole) w dowolną z 4 stron.
- **Tylko statek bez trafienia.** Trafienia nigdy nie zmieniają pozycji, więc
  cele AI z trybu hunt/target zostają aktualne.
- **Nie na pole ostrzelane przez wroga** (salwa, torpeda, nalot — pudło albo
  trafienie). Inaczej statek schowany na pudle byłby dla AI niewidzialny, bo
  AI nie strzela drugi raz w to samo pole. Pole odkryte sonarem wroga ruchu
  nie blokuje: sonar nie strzela.
- **Walidacja jak przy rozstawianiu:** granice planszy i zakaz stykania się
  statków. Statek zabiera wyposażenie ze sobą.
- **AI się nie rusza** i nie dostaje informacji o ruchu gracza.
- **Do obserwacji:** czy ruch nie jest za silny przy nieruchomym AI; jeśli
  tak — ruch AI albo wyższy koszt.

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
widoczność i mnożnik złóż. Z Epikiem J (#143) doszła trzecia brama, „wody
nieznane”, do tajnego akwenu 4.

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

✅ (#108) Akwen 3 „Morze Mgieł” (sztorm 10%, widoczność 0, złoża ×2) i otwarty
ocean. Sektor bramy leży na wschodniej krawędzi Oceanu Sztormów, a po drugiej
stronie na zachodniej krawędzi Morza Mgieł. Bramę otwiera zbadana Nawigacja
oceaniczna, bez bitwy i bez stanu w profilu. Działa w obie strony, a przejście
kosztuje 1 tik. Przy widoczności 0 flota odkrywa tylko sektor, na który
wpływa. Rejs o 2 sektory (Żegluga dwusektorowa) odkrywa też sektor po drodze:
jeden krok od startu w stronę celu, przy ruchu 2+1 po skosie. Ten sektor daje
kartografię, ale nie grozi sztormem i nie liczy się jako odwiedzony. Na Morzu
Mgieł jest 5 wysp na planszach 12×12:
- od komandora: Kotwicowisko Mgieł (stocznia poziomu 3), Wyspa Szeptów
  (złoto 2/tik), Mielizna Widm (drewno 4/tik),
- od admirała: Archipelag Ślepców (stal 3/tik) i Cytadela Mgieł (największy
  łup, 340 XP).
Każda daje więcej XP i łupów niż wyspy Oceanu Sztormów. Kapitan może wpłynąć
na Morze Mgieł i zarabiać na kartografii, ale wyspy zdobędzie od komandora.

✅ (#143, Epic J D5) Akwen 4 „Wody Czarnej Perły” (sztorm 30%, widoczność 1,
złoża ×3) i wody nieznane. Sektor bramy leży na wschodniej krawędzi Morza
Mgieł, a po drugiej stronie na zachodniej krawędzi akwenu 4. Bramę otwiera
**mapa akwenu**, przedmiot w profilu kapitana. Przejście jest bez bitwy,
kosztuje 1 tik i działa w obie strony; mapy się nie zużywa.
- Mapę kupuje się tylko na rynku Bazaru we Mgle za 150 złota razy mnożnik
  ceny rynku z reputacji (#142): kupiec 135, korsarz 188 złota.
  Płaci magazyn portu, resztę ładownia, a transakcja kosztuje 1 tik.
- Drugie źródło to skarb ekspedycji na Wyspie Zaginionych (Morze Mgieł, #144):
  35% po etapie 3, mapa przychodzi z `SeaChartSource::Expedition`.
- **Mapy nie ma w łupach z bitew** — ma zostać najcenniejszym przedmiotem
  w grze (pytanie z #56 rozstrzygnięte).

Akwen 4 to treść poza końcówką gry: 3 wyspy od admirała na planszach 12×12:
- Przystań Kaperska ze stocznią poziomu 3,
- Rafa Perłowa ze złotem 2/tik (×3 z motywu),
- Wrak Czarnej Perły z fortem (bandera po oblężeniu) i największym łupem:
  450 XP oraz 50 drewna, 50 stali i 50 złota.

Wróg pływa ze wszystkimi trzema wariantami ze zdobyczy. Akwen nie ma portu
handlowego, bo po handel wraca się na Mgły. Spotkania na morzu są takie jak
na Mgłach. Zdobycie, złoża i kontrataki działają tak jak na innych akwenach.

**Przejścia między akwenami — trzy rodzaje bram (mieszane), wszystkie działają ✅:**
1. **Strzeżona cieśnina** — bitwa ze strażnikiem (#107); fort w cieśninie odłożony,
2. **Otwarty ocean** — wymaga technologii („Nawigacja oceaniczna", #108),
3. **Wody nieznane** — wymaga **mapy akwenu** (rynek Mgieł albo ekspedycja na Wyspie Zaginionych; #143, #144).

Mapa akwenu = najcenniejszy przedmiot w grze; to domyka wątek „mapy jako
towar" i daje sens handlowi i questom.

✅ (#153) Z technologią Nawigator (filar V) flota płynie wyznaczoną trasą po
odkrytych sektorach wielu akwenów i przez otwarte bramy. Każdy krok jest
zwykłym rejsem albo przejściem przez bramę i kosztuje tyle tików co zwykle.

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
  ✅ (#140) Jeden port handlowy na akwen: Targ Rozbitków, Faktoria Kotwic,
  Bazar we Mgle. Nie ma na nim bitwy, bandery, złoża ani kontrataków i nie
  liczy się do pełnej bandery akwenu. Ma magazyn jak każda wyspa. Sektor
  losuje seed akwenu osobnym rzutem we wnętrzu mapy, nie obok wyspy, więc
  rozkład wysp trasy się nie zmienia. Na mapie: zielona wyspa z kramem.
- **Osada gracza** ✅ (Epic F): budynki na posiadanej wyspie, każdy typ raz,
  bez poziomów, budowa natychmiastowa. Tartak i kopalnia przy złożu swojego
  surowca podwajają urobek. Fort chroni wyspę pod nieobecność floty: kontratak
  kończy się grabieżą połowy magazynu zamiast utraty wyspy. Rozbudowa stoczni
  podnosi istniejącą stocznię o poziom (Zatoka 1→2). Uczelnia prowadzi
  badania drzewka (filar V). Po utracie wyspy budynki stoją nieczynne do odbicia.
- **Dzika/questowa** — wraki, ekspedycje, sekrety (czarna perła)
  ✅ (#144) Jedna dzika wyspa na akwen: Wyspa Papug, Wyspa Dymiącej Góry,
  Wyspa Zaginionych i Wyspa Czaszki. Nie ma na niej bitwy, bandery, magazynu
  ani złoża. Sektor losuje seed akwenu osobnym rzutem po porcie handlowym, więc
  wyspy, bramy i port stoją tam, gdzie stały. Na mapie: zielona wyspa z palmą.
  Spotkania na morzu nie trafiają na jej sektor. Ekspedycja: filar VII.

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
2. **abordaż** ✅ (#118): ostatni niezatopiony statek wroga można abordażować
   zamiast dobić → statek jako łup; ryzyko przegranej próby:
   - tylko w bitwach wyprawy z bronią specjalną (zdobycie, obrona, strażnik),
     nie w treningu; jedna próba na bitwę,
   - ⚡3, czyli cała tura; próba kończy turę,
   - rzut k6 z seeda gry i numeru tury, sukces od 5+; próg spada o 1, gdy
     co najmniej połowa pól statku jest trafiona (nie niżej niż 2+).
     Modyfikatory to lista — technologie i perki (Epic K) dojdą jako kolejne,
   - porażka: tura stracona, statek walczy dalej i trzeba go dobić,
   - sukces: wygrana z XP i łupem jak zwykle, a zdobyty statek czeka w profilu
     na decyzję: **wyremontuj i dołącz** (do floty jako uszkodzony, typ
     z długości, bez wymogu rangi) albo **rozbierz** (połowa kosztu budowy do
     ładowni, nadmiar przepada — decyzja czeka, więc można najpierw rozładować).
     ✅ (#121) Trzecia opcja, **zbadaj konstrukcję**, jest tylko dla wariantu
     ze zdobyczy, którego kapitan nie zna: statek przepada, a wariant od razu
     trafia do stoczni (filar V),
3. **oblężenie fortu** — „battleship w innym smaku": plansza fortu (mury,
   działa nadbrzeżne, **magazyn prochu** = trafienie wywołuje eksplozję
   łańcuchową). Ta sama mechanika obsługuje bramy-cieśniny.

✅ (#119) Silnik oblężenia, także jako bitwa treningowa „oblężenie”.

✅ (#120) **Forty na trzech twierdzach:** Twierdza Admiralicji, Bastion Fal
i Cytadela Mgieł, czyli najwyższa wyspa każdego akwenu (D5). Twierdzę zdobywa
się w dwóch fazach, a obie wymagają floty przy wyspie:
- **potyczka** z flotą strażniczą jak na każdej wyspie (abordaż działa).
  Wygrana płaci jak porażka (XP i łup za przegraną) i **oblega** wyspę,
  ale nie stawia bandery. Oblężonej wyspy nie atakuje się drugi raz.
  Przegrana potyczka niczego nie zapamiętuje,
- **oblężenie** fortu zaczyna się osobną akcją „Rozpocznij oblężenie”. Plansza
  fortu 10×10, więc skład mieści się w 20 polach. Każda próba to nowy układ
  fortu (seed świata, wyspy i numeru próby). Abordażu nie ma, bo fort nie jest
  statkiem, a mury nie liczą się do zatopionych jednostek wroga,
- **wygrane oblężenie** stawia banderę i płaci pełną stawkę wyspy (XP i łup
  za wygraną), złoże rusza jak przy każdym zdobyciu,
- **przegrane oblężenie** (D7): zatopione statki przepadają, XP i łup jak za
  porażkę, a wyspa zostaje oblężona, więc szturm można ponawiać bez potyczki,
- oblężona wyspa nie jest pod banderą, więc **kontratak** jej nie dotyczy.
  Twierdza utracona w kontrataku wraca do stanu sprzed potyczki,
- rejestr bitew ma rodzaj `siege` obok `capture`, `defense` i `guardian`;
  dziennik notuje oblężenie i obroniony fort,
- fort w strzeżonej cieśninie jest odłożony (D6). Stare profile się wczytują,
  ale twierdze zdobyte przed #120 zostają pod banderą (profile można resetować).
- **Plansza fortu 10×10:** 4 działa po jednym polu, 3 odcinki muru po 2–3
  pola i 1 magazyn prochu. Liczby są w jednym miejscu (`FortRules`, D3).
- **Układ** jest deterministyczny z seeda (D4). Konstrukcje mieszczą się na
  planszy i się nie nakładają, ale w odróżnieniu od statków mogą się stykać.
  Magazyn zawsze stoi przy murze, więc eksplozja 3×3 ma co trafić.
- **Wygrana** przychodzi dopiero po zniszczeniu ostatniego działa. Trafiony mur
  się odsłania i pochłania strzał, ale nie liczy się do wygranej.
- **Magazyn prochu:** trafienie wywołuje eksplozję 3×3 wokół magazynu. Trafia
  wszystko na tych polach, także działa i mury, a puste pola liczą się jako
  pudła. Eksplozja nie ma osobnego stanu, bo wynika z trafienia magazynu.
- **Fort strzela** jedną zwykłą salwą na turę, dopóki ma choć jedno działo.
  Nie ma broni specjalnych ani energii.
- **Gracz** ma pełny arsenał, energię i ruch statku. Sonar widzi mur jak
  statek, a torpeda i nalot go trafiają, więc mury działają jak przynęta.

**Zdobyta wyspa przestaje być areną powtarzalnych bitew** ✅ (Epic E): pierwsza
wygrana stawia banderę. Posiadana wyspa ma pracujące złoże, a jej magazyn jest
stawką kontrataku. Zatoka Rozbitka to bezpieczna przystań: da się ją zdobyć,
ale nikt jej nie atakuje. Nowe źródła walki to NPC, piraci i **kontrataki**:
- najwyżej jeden naraz; po zdobyciu albo rozstrzygnięciu 8 tików spokoju,
  potem 15%/tik (seed+tik); cel to losowa posiadana wyspa z dowolnego
  akwenu poza bezpiecznymi przystaniami (Zatoka Rozbitka),
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
  którejkolwiek wyspy tego akwenu.

✅ (#109) Kontrataki i pełna bandera na wielu akwenach (D4 z Epica I):
- pełna bandera liczy się osobno na każdym akwenie. Morze Rozbitków pod
  banderą daje bonus przy obronie swoich wysp, a przy obronie wyspy Oceanu
  Sztormów już nie, dopóki cały Ocean nie jest pod banderą,
- kontratak obejmuje cały świat: dalej jeden naraz, a celem może być wyspa
  innego akwenu niż ten, na którym stoi flota. Rzut zależy tylko od seeda i
  tiku, nie od akwenu floty,
- zapowiedź dalej 12 tików. Droga na wyspę innego akwenu to rejsy do bramy,
  1 tik przejścia i rejsy do wyspy. Obronę trzeba przyjąć przed uderzeniem,
  więc zdąży tylko droga krótsza niż czas do uderzenia,
- zapowiedź podaje akwen celu, a gdy flota jest gdzie indziej, zestawia
  pozostałe tiki z drogą przez bramę. Przegląd akwenu pokazuje bandery
  na każdym znanym akwenie,
- fort chroni wyspę pod nieobecność floty, także gdy flota jest na innym
  akwenie. Urobek złoża do tiku uderzenia liczy się z motywu akwenu wyspy.
Do obserwacji: czy kontrataki nie wymuszają ciągłego krążenia przez bramy.
Jeśli tak, można je ograniczyć do akwenu floty albo wydłużyć zapowiedź dla
celów na innym akwenie.

Obrona wyspy bez floty: fort w osadzie (#81, Filar III wyżej).

## Filar IV — Ekonomia: trzy surowce, ładownia, konwoje ✅

- **Drewno** (→ kadłuby, budynki), **stal** (→ uzbrojenie, forty),
  **złoto** (→ handel, uczelnie, najemnicy). Obecne „materiały" stają się
  drewnem. Na start dokładnie trzy — budżet złożoności. ✅ Złoto wchodzi
  od razu (złoża na mapie), nawet jeśli jego sink (handel/uczelnie)
  pojawi się w późniejszym plastrze — migracja surowców tylko raz.
  ✅ (#140) **Rynek** w porcie handlowym (filar III): stały kurs akwenu,
  bez dynamicznego popytu. Za 1 złoto kupisz na Morzu Rozbitków 5 drewna
  albo 3 stali, na Oceanie Sztormów 5 drewna albo 4 stali (tańsza stal),
  na Morzu Mgieł 3 drewna albo 3 stali (droższe drewno). Sprzedaż idzie po
  połowie ceny kupna, więc między akwenami nie ma arbitrażu. Transakcje
  za złoto, 1 tik każda (sprzedaż w porcjach po 1 złoto, kupno liczone od
  całego zlecenia, #142): płaci magazyn portu, resztę ładownia,
  a towar i złoto trafiają do ładowni (bez miejsca = odrzucenie). Statek
  sprzedasz za złoto bez stoczni: zwrot jak przy złomowaniu (#124),
  przeliczony po kursie sprzedaży, w dół z całości. Mnożnik ceny
  w procentach daje reputacja (#142), a handlarz na morzu (#141) dolicza
  do niego swoją marżę.
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

✅ (#108) Trzeci poziom drzewka: 60 drewna, 50 stali, 30 złota i 14 tików, od
rangi kapitan, po zbadaniu poziomu 2 tej samej gałęzi. Pierwsza technologia
poziomu 3 to Nawigacja oceaniczna (po Żegludze dwusektorowej), która otwiera
otwarty ocean między Oceanem Sztormów a Morzem Mgieł.

✅ (#153) Nawigator, poziom 2 Nawigacji obok Żeglugi dwusektorowej (wymaga Map
sztormowych i rangi sternik; 40 drewna, 30 stali, 15 złota, 10 tików), planuje
dłuższą podróż przy tym samym koszcie w tikach:
- trasa prowadzi tylko po odkrytych sektorach i przez otwarte bramy akwenów.
  Cel też musi być odkryty, a przy kroku o 2 sektory także sektor po drodze,
- krok ma długość zwykłego rejsu: 1 sektor albo 2 z Żeglugą dwusektorową,
- domyślnie trasa jest najkrótsza w tikach. Przy remisie wygrywa trasa
  z mniejszą liczbą sektorów ze sztormem, a potem z mniejszą liczbą dokowań po drodze,
- przełącznik „omijaj nieodwiedzone sektory” daje trasę bez ryzyka sztormu,
  nawet jeśli dłuższą, albo informację, że takiej trasy nie ma,
- przed startem widać koszt w tikach, liczbę sektorów z ryzykiem sztormu
  i zdążenie przed zapowiedzianym kontratakiem. Zdąży trasa krótsza niż czas
  do uderzenia, jak w #109,
- każdy krok to zwykły rejs albo przejście przez bramę, z animacją i tikiem
  przez `passTicks()`. Trasa staje na sztormie, spotkaniu, nowej zapowiedzi
  kontrataku i dokowaniu przy wyspie, porcie albo dzikiej wyspie. „Płyń dalej”
  liczy trasę od nowa z miejsca postoju, a „Anuluj” ją porzuca.

Plan liczy backend (`NavigatorRoute`, `GET /profiles/{id}/navigator/route`).
Rejs prowadzi front przez zwykłe `/sail` i `/gates/{gateId}/pass`, więc reguły
rejsu się nie zmieniają. Nawigator jest poziomem 2, więc może też wypaść jako artefakt.

✅ (#96) Ekonomia: Pojemne ładownie (ładownia statku +50%, w dół) i Dok
remontowy (remont za pół ceny, w górę) w Kadłubach; Lepsze narzędzia (złoże
+1/tik, tartak i kopalnia podwajają powiększony urobek) w Osadach.

Każdy węzeł ma **dwie ścieżki odblokowania**:
- **standardową** — surowce + tiki + wymagana uczelnia (wolniej),
- **questową** — artefakt/wyczyn = natychmiast (Twoje „przyspieszenie").
Kilka węzłów-legend **tylko questowych** (czarna perła → „Nawigacja Legend").
Questy nie są obok gry — są skrótami i sekretami w drzewku.

✅ (#121) **Druga ścieżka działa jako „ze zdobyczy”:** technologia może mieć
odblokowanie `boarding` zamiast uczelni. Taka technologia nie ma poziomu ani
kosztu, uczelnia jej nie bada, a odblokowuje ją zbadanie konstrukcji statku
zdobytego abordażem (filar III), od razu i bez tików. Drzewko pokazuje te
pozycje osobno, w sekcji „Tylko z abordażu”. Na start są to trzy warianty,
które wróg ma w składzie na swoim akwenie:

| wariant | kadłub | wyposażenie | u wroga |
|---|---|---|---|
| Kuter torpedowy | kuter (2) | torpeda zamiast sonaru | Ocean Sztormów |
| Ciężki niszczyciel | niszczyciel (3) | 2 torpedy | Ocean Sztormów |
| Lotniskowiec eskortowy | lotniskowiec (4) | nalot + sonar | Morze Mgieł |

✅ (#144) **Trzecia ścieżka to quest** (rozstrzyga otwarte pytanie o ścieżkę
questową, Epic J D7):
- **Artefakt** to skarb etapu 3 ekspedycji (filar VII). Od razu odblokowuje
  losową niezbadaną technologię z poziomów 1–2 uczelni, bez kosztu, tików
  i uczelni. Nie wypada technologia badana w tej chwili. Poziom 3 (Nawigacja
  oceaniczna, Kuter remontowy) zostaje na uczelni, żeby artefakt nie
  przeskoczył progresji akwenów. Technologia z artefaktu ma dalej
  `unlock = academy`, a profil pamięta, że przyszła z questu
  (`CaptainProfile::artifacts()`). W drzewku węzeł dostaje znacznik
  „artefakt” i trafia też do sekcji „Z questu”.
- **Legenda „Czarna perła”** (`unlock = quest`) to jedyna technologia tylko
  z questu, skarb Wyspy Czaszki na Wodach Czarnej Perły. Daje +1 do
  widoczności na wszystkich akwenach, więc na Morzu Mgieł flota widzi
  sąsiednie sektory zamiast tylko swojego. Uczelnia jej nie bada
  (`409 TECHNOLOGY_QUEST_ONLY`).

Skład wroga (decyzje Jacka z 23.10): każda wyspa akwenu ma jeden z jego
wariantów, wybrany z seeda świata i id wyspy. Wariant zastępuje pierwszy zwykły
statek swojego kadłuba w lustrze, więc pojawia się tylko wtedy, gdy gracz
przyprowadzi statek tego kadłuba. Strażnik cieśniny ma oba warianty Oceanu.
W obronie przed kontratakiem napastnik ma tylko lustro wariantów gracza, także
tych zbadanych (D6). Kuter i lotniskowiec bez wariantu to statek podstawowy,
który stocznia buduje obok wariantu. Stawiacz min dojdzie tą samą drogą z #123.

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
- ✅ (#121) **Kuter torpedowy, ciężki niszczyciel, lotniskowiec eskortowy**:
  warianty tylko z abordażu (wyżej, druga ścieżka odblokowania).
- ✅ (#152) **Kuter remontowy** (Kadłuby, poziom 3, po Doku remontowym, ranga
  kapitan): kuter z warsztatem zamiast sonaru, bez broni, więc nie eskortuje
  konwoju; koszt budowy i remontu jak kutra. Na każdym tiku (rejs,
  przeładunek, start bitwy, handel) każdy sprawny kuter remontowy naprawia
  jeden uszkodzony statek do 3 pól, od najmniejszego, za koszt remontu
  z ładowni (z Dokiem remontowym). Bez surowców w ładowni nie naprawia,
  uszkodzony nie naprawia (także siebie), a bitwa nie tyka. Lotniskowiec
  i transportowiec wymagają stoczni. Każda naprawa trafia do dziennika;
  przełącznik w zakładce Flota wyłącza naprawy, żeby oszczędzać ładunek.
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

✅ (#141) Spotkania na morzu. Rejs bez sztormu kończy się spotkaniem
z szansą 8% (z seeda i tiku, jak sztorm), nigdy na sektorze wyspy ani
bramy; w jednym rejsie najwyżej sztorm albo spotkanie. 60% to handlarz,
40% pirat. Spotkanie zatrzymuje rejs do decyzji, a baner na ekranie Rejs
podaje skutki każdego wyboru z góry.
- **Handlarz:** handel na miejscu jednym zleceniem za 1 tik (kupno
  i sprzedaż drewna i stali za złoto z ładowni po kursie portu akwenu
  z mnożnikiem ceny 125%), napad albo minięcie bez skutków. Zlecenie idzie
  w sztukach: kupno płaci za każdą zaczętą porcję, sprzedaż dostaje złoto
  za pełne.
- **Pirat:** walka, ucieczka (1 tik i 20% każdego surowca w ładowni) albo
  okup w złocie z ładowni: 10 / 25 / 35 na Morzu Rozbitków / Oceanie
  Sztormów / Morzu Mgieł.
- **Bitwa spotkania** (napad, walka) toczy się na otwartym morzu w trybie
  fun, z abordażem. Wróg ma stały skład akwenu: pirat 8 / 14 / 17 pól,
  handlarz z transportowcem i eskortą 8 / 10 / 12 pól, na planszy 8×8 /
  10×10 / 11×11. Łup trafia do ładowni, nadmiar idzie za burtę; przegrana
  daje ¼ XP bez łupu. Pirat: 10 stali i 15 złota / 20 i 25 / 25 i 35.
  Napad: 30 drewna, 15 stali, 10 złota / 35, 35, 20 / 45, 35, 30.
- Napad i pokonanie pirata zapisują się w rejestrze bitew (`encounter`)
  i w dzienniku.

✅ (#142, Epic J D4) Reputacja kupiec ↔ korsarz. Jedna oś od −100
(korsarz) do +100 (kupiec), nowy kapitan i profil sprzed reputacji startują
od 0. Wartość jest zapisana w profilu, a nie liczona z historii.
- **Zmiany:** każda transakcja na rynku portu i każde zlecenie u handlarza
  +2, pokonany pirat +5 (po wygranej bitwie), napad na handlarza −20 już
  przy starcie bitwy, bez względu na wynik. Sprzedaż statku i kupno mapy
  reputacji nie zmieniają. Nie ma dryfu w czasie: reputacja stoi, dopóki
  kapitan czegoś nie zrobi.
- **Progi (włącznie):** od +40 kupiec, ceny na rynku −10% (mnożnik 90%);
  od −40 korsarz, ceny +25% (125%); od −70 embargo. Mnożnik działa na
  kursy towarów, cenę mapy, sprzedaż statku i ceny handlarza (handlarz
  dolicza swoje 25%). Kupno na rynku liczy się od całego zlecenia
  (floor(złoto × kurs × 100 / mnożnik)), bo przy porcjach po 1 złoto rabat
  kupca ginął w zaokrągleniu: 10 złota kupuje kupcowi 55 drewna zamiast 50.
- **Embargo:** port odmawia handlu, sprzedaży statku i mapy, a handlarz na
  morzu handlu (`409 EMBARGO`). Przeładunek do własnego magazynu w porcie
  działa. Handlarze nie przychodzą: z embarga wyprowadzają tylko pokonani
  piraci.
- **Łowcy nagród** przychodzą przy embargu zamiast handlarza (te same
  rzuty z seeda i tiku, piraci bez zmian). Z łowcami jest tylko bitwa:
  bez minięcia, ucieczki, okupu i handlu. Flota to piraci akwenu i jeden
  niszczyciel więcej, na tej samej planszy, a XP i łup ×1,25 (przegrana
  ¼ XP). Wygrana z łowcami reputacji nie zmienia. Do floty bez sprawnego
  statku z bronią łowcy nie przychodzą, żeby wymuszona bitwa nie zablokowała
  rejsu.
- **Telegrafowanie:** HUD ekranu Rejs pokazuje wartość, poziom, skutek
  i próg embarga na pasku osi, a w dymku zmiany za zdarzenia i najbliższe
  progi. Rynek podaje poziom i progi, a przy embargu powód blokady. Baner
  spotkania podaje skutek każdego wyboru dla reputacji („reputacja −20
  (−25 → −45)”) i ostrzega, gdy napad przekroczy próg. Przejście progu
  trafia do dziennika (`reputation-tier`).

## Filar VII — Questy

- **Ekspedycja w głąb lądu** (dzikie wyspy): press-your-luck — idziesz
  głębiej po skarb czy wracasz z tym, co masz.
- Nagrody: mapy akwenów, artefakty (questowe ścieżki drzewka), statki,
  reputacja. Fabuła = krótkie teksty przy questach i bramach akwenów.

✅ (#144, Epic J D6) **Ekspedycja w głąb dzikiej wyspy.** Decyzje Jacka z 25.10
(przyciski, wszystko według rekomendacji):
- **Etapy:** są 3, ryzyko porażki 20%, 35% i 50%. Rzut liczy się z seeda
  świata, tiku, wyspy i etapu. Start to od razu etap 1. Przed każdym
  następnym kapitan wybiera „głębiej” albo „wracaj”.
- **Koszt:** każdy etap kosztuje 1 tik (przez `passTicks()`), powrót nic.
- **Odpoczynek:** po zakończonej ekspedycji ta sama wyspa czeka 15 tików.
- **Warunki startu:** ranga wyspy i choć jeden sprawny statek. W trakcie
  ekspedycji flota nie odpływa (`409 INLAND_IN_PROGRESS`).
- **Porażka:** zabiera wszystko zebrane w tej ekspedycji i uszkadza jeden
  sprawny statek floty, wybrany z seeda. Ładunek sprzed ekspedycji zostaje.
- **Powrót:** zebrane nagrody idą do ładowni. Na wyspie nie ma magazynu,
  więc nadmiar ponad pojemność przepada.
- **Fabuła:** każdy etap ma krótki tekst, odsłaniany po jego przejściu.

| dzika wyspa | akwen | ranga | etap 1 | etap 2 (+) | etap 3 (+) | skarb po etapie 3 |
|---|---|---|---|---|---|---|
| Wyspa Papug | Morze Rozbitków | majtek | 8 drewna | 6 drewna, 6 stali | 6 stali, 5 złota | artefakt 20% |
| Wyspa Dymiącej Góry | Ocean Sztormów | porucznik | 15 drewna | 12 drewna, 12 stali | 12 stali, 10 złota | artefakt 30% |
| Wyspa Zaginionych | Morze Mgieł | komandor | 20 drewna | 16 drewna, 16 stali | 16 stali, 14 złota | mapa Wód Czarnej Perły 35%, artefakt 30% |
| Wyspa Czaszki | Wody Czarnej Perły | admirał | 25 drewna | 20 drewna, 20 stali | 20 stali, 20 złota | Czarna perła 30%, artefakt 30% |

Pełną ekspedycję przechodzi się z szansą 26% (80% × 65% × 50%). Skarb losuje
jeden rzut po szansach z tabeli. Gdy kapitan już ma wylosowany skarb (mapę,
legendę) albo nie ma czego wylosować jako artefakt, dostaje 20 złota.
API: `POST /profiles/{id}/wild-islands/{islandId}/expedition {action: start|deeper|return}`.
W `/expedition`: `world.wildIsland`, czyli etapy z ryzykiem, nagrodą
i tekstem, skarby, odpoczynek, blokadę i ekspedycję w toku, a także
`world.visibility` i `research.artifacts`.

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
Ścieżki nie wykluczają się — profil gracza wyłania się z akcji;
to wchłania pomysł „perków kapitanów" z poczekalni.

**Model specjalizacji (Epic K, #160)** ✅ — decyzje Jacka z 25.10.2026:

- **Punkty wyłącznie z akcji, obie ścieżki naraz** (D1). Bez wstecznego
  naliczania (D7): profile sprzed specjalizacji startują od zera.
- **5 poziomów na ścieżkę, progi 20, 60, 120, 200 i 300 punktów** (D2).
- **Punktacja (D3):**

| ścieżka | akcja | punkty |
|---|---|---|
| bojowa | wygrana bitwa wyprawy (każdego rodzaju) | +5 |
| bojowa | zatopiony wróg, także w przegranej | +1 |
| bojowa | udany abordaż | +5 obok wygranej |
| bojowa | wygrana obrona wyspy | +5 obok wygranej |
| bojowa | wygrane oblężenie fortu | +10 obok wygranej |
| ekonomiczna | złoto obrotu na rynku portu i u handlarza (zapłacone albo otrzymane) | +1 za 20 |
| ekonomiczna | ładunek rozładowany na innej wyspie niż załadunek | +1 za 20 jednostek |
| ekonomiczna | urobek, który złoża odłożyły do magazynu (po limicie) | +1 za 30 jednostek |
| ekonomiczna | nowo odkryty sektor | +1 |

- **Premie sumują się z wygraną:** wygrane oblężenie to 15 pkt, wygrana
  abordażem 10. Sprzedaż statku i kupno mapy nie są obrotem.
- **Ułamki nie przepadają:** profil liczy surowe jednostki akcji (złoto,
  ładunek, urobek), a punkty wychodzą z dzielenia.
- **Pochodzenie ładunku:** ładownia zostaje jedną pulą, obok niej licznik
  „wyspa → ile jednostek stamtąd jeszcze wiozę”. Rozładunek liczy najpierw
  jednostki z innych wysp. Ładunek bez pochodzenia (łup, kartografia, handel)
  nie jest transportem, a straty ładowni (sztorm, płatność, kuter remontowy)
  zabierają go jako pierwszy.
- **Perki (D4):** jeden na poziom, działają od zdobycia poziomu. Wróg ich
  nie dostaje (D6). Ścieżka bojowa wpływa na samą bitwę umiarkowanie, od
  poziomu 3.

| poziom | bojowa (#161) | ekonomiczna (#162) |
|---|---|---|
| 1 | remont po wygranej −20% | ładownia +10% |
| 2 | próg abordażu −1 | ceny rynku −5% |
| 3 | +1 użycie sonaru w bitwie | budowa statków −10% |
| 4 | łup z bitew +20% | magazyn złóż +5 tików produkcji |
| 5 | +1⚡ w pierwszej turze bitwy | ceny rynku −10% łącznie, handlarz bez dopłaty |

- **UI (D5):** zakładka „Kapitan” w Rejsie (poziomy, postęp do progu,
  źródła punktów z resztą do kolejnego punktu, perki zdobyte i następny),
  poziomy w HUD, komunikat o awansie do potwierdzenia i wpis w dzienniku.

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

- Rangi vs akweny: czy progi rosną z liczbą akwenów; gdzie osiągalny admirał; czym zapełnić lukę 250–600 XP (dziś wyspy II poziomu, a od Epica G także drugi poziom drzewka) i które rangi bramkują akwen 2? Akwen 2 otwiera się od porucznika, a admirał to końcówka gry na akwenie 3.
- Pobojowisko: procent odzysku, po ilu tikach resztki znikają?
- Balans z obserwacji: pełna utrata floty przy przegranej, sztormy 20%, koszty statków
- Czy kiedyś dzielimy flotę na zespoły z własną pozycją?
- Budowa trwająca N tików (filar II): kiedy i czy jednocześnie dla statków i budynków?

## Poczekalnia (pomysły wciąż aktualne, poza głównym torem)

Eventy losowe w bitwie (mgła, cisza morska, prąd) · frakcje jako skórki
(technologia/magia/rasa — flavor over mechanics) · miny (#123: stawiacze min —
gracz przed bitwą rozmieszcza miny, a statek wroga, który na nią trafi, jest
trafiony; do zbalansowania liczba min, kto je ma i czy wróg też; wrogi stawiacz
min dojdzie jako kolejny wariant tylko z abordażu, ścieżką z #121) · replay partii ·
statystyki/osiągnięcia · daily challenge · PvP (tylko na czystym rulesecie,
jeśli kiedykolwiek). Perki kapitanów przeniesione do Filaru VIII
(specjalizacje).

## Zestawy floty (#125) ✅

Decyzje Jacka z 24.10.2026:

- Profil trzyma najwyżej **5 zestawów** z nazwami. Zestaw to id statków floty,
  ich pozycje i orientacja oraz rozmiar planszy, na której go zapisano.
  Pozycje spełniają zasady rozstawiania. Zapis pod istniejącą nazwą
  nadpisuje zestaw.
- **Skład z zestawu wybiera się na ekranie Rejs**, przy składzie bitewnym,
  bo skład jest częścią gry od jej startu. Zestaw bierze sprawne statki
  w kolejności zapisu, do limitu pól. Statki utracone, złomowane,
  w remoncie albo ponad limit są pomijane z komunikatem.
- **Rozstawianie** ustawia statki zestawu wybranego do bitwy. Na planszy
  tego samego rozmiaru wracają na zapisane pozycje. Statki, które się nie
  mieszczą, czekają na ręczne ustawienie. Na planszy innego rozmiaru zestaw
  daje sam skład. Na ekranie rozstawiania zestaw zapisuje się, wczytuje,
  zmienia nazwę i usuwa. W treningu, gdzie nie ma statków profilu, zestawów nie ma.
