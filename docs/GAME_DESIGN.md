# GAME_DESIGN.md — wizja gry

Żywy dokument — rama, nie specyfikacja. Będzie ewoluować; decyzje kierunkowe
oznaczamy ✅, otwarte pytania trzymamy na końcu. Spisane po burzach mózgów
2026-07-11/12.

## Wizja jednym zdaniem

Od **rozbitka na tratwie** do **admirała z lotniskowcem**: meta-gra
eksploracyjno-ekonomiczna (wolne morze, wyspy, stocznie, handel mapami)
zbudowana nad klasyczną bitwą w statki, która pozostaje sercem pętli:

```
mapa → wyspa → bitwa → łup/strata → rozbudowa floty → dalej
```

## Pięć filarów

### 1. Bitwa (istnieje dziś jako gra)

- ✅ **Broń wynika ze składu floty**, nie z abstrakcyjnych limitów:
  - nalot — masz lotniskowiec; zatopiony lotniskowiec = koniec nalotów w tej bitwie,
  - torpeda — startuje z niszczyciela / okrętu podwodnego
    (rozwinięcie istniejącej reguły „torpeda z niezatopionego statku"),
  - sonar — okręt zwiadowczy.
- Limity użyć zostają jako „amunicja"; zasięg/kształt broni rośnie z technologią.
- Wymaga refaktoru **`WeaponSpec`**: parametry broni (kształt, zasięg, limit)
  zamiast hardcodu — pierwszy konkretny krok kodowy całej wizji, sensowny
  niezależnie od reszty.
- Bitwa pozostaje czystym modułem: wejście = skład floty + parametry broni,
  wyjście = wynik + straty. Agregat `Game` nie wie o meta-grze.

### 2. Flota jako majątek

- ✅ Statki **giną albo wymagają remontu** po bitwie.
- ✅ **Stocznie** (na wyspach, z poziomami) budują i remontują flotę:
  plaża skleci tratwę, lotniskowiec wymaga wielkiej stoczni.
- Geografia = logistyka: poobijana flota musi *dopłynąć* do remontu.

### 3. Kapitan

- ✅ Rangi: **rozbitek → marynarz → kapitan → admirał** — wymagają czasu
  i doświadczenia.
- ✅ **XP i technologia nigdy nie giną** — także po przegranej bitwie
  (z porażek też się uczymy).
- Rama narracyjna za darmo: start gry = rozbitek, który sklecił tratwę z wraku.
- Bramki progresji (rozdzielone, żeby lotniskowiec nie miał czterech kłódek naraz):
  - **ranga** → typy statków + dostęp do wysp,
  - **technologia** (schematy z wysp) → ulepszenia (zasięg sonaru, ukośne torpedy…),
  - **materiały + stocznia** → budowa i remont konkretnych jednostek.

### 4. Świat

- ✅ **Wolne morze** (na początek małe), mgła świata, generacja z seeda.
- ✅ **Mapy archipelagów jako towar**: kupno/sprzedaż, nagrody za questy;
  **kartograf jako zawód** — żeglujesz po niezbadanych wodach, nanosisz
  archipelagi, sprzedajesz mapy w portach (trzecia ścieżka dochodu obok
  bitew i questów).
- Eventy podróży: **sztorm / tajfun / wir** — losowe (z seeda), zapowiadane
  z wyprzedzeniem; niezbadane wody = większe ryzyko (risk/reward kartografii).
- Symetria mgły: bitwa = odkrywanie planszy przeciwnika strzałami i sonarem,
  morze = odkrywanie świata żeglugą i mapami. Ta sama mechanika na dwóch piętrach.

### 5. Ekonomia

- **Materiały** = koszt budowy/remontu (krążą); **schematy** = odblokowanie
  typu/ulepszenia (zdobywa się raz).
- **Bezpiecznik anty-softlock**: tratwa jest zawsze darmowa, a najniższe
  misje/łowiska/kartografia dają dochód bez ryzyka bitwy. Gracz może stracić
  wszystko *oprócz* rangi, technologii i możliwości odbicia się.
- ✅ **PvP odłożone** — na początek go nie ma; jeśli kiedyś wejdzie,
  to wyłącznie na czystym rulesecie (progresja psuje balans PvP).

## Zasady projektowe

1. **Determinizm z seeda** — świat, floty i eventy generowane deterministycznie
   (jak `FleetGenerator`); port losowości w domenie zamiast globalnego RNG.
   Daje powtarzalne testy, replay i daily challenge.
2. **Telegrafowanie > zaskoczenie** — eventy losowe zapowiadane („barometr
   spada — sztorm za 2 tury"); zapowiedziana losowość to decyzje, czysta
   losowość to frustracja.
3. **Flavor over mechanics dla frakcji** — technologia/magia/rasa na start
   jako czysta skórka nazw/ikon w UI (sonar = echolokacja = kryształ);
   różnicowanie mechaniczne dopiero po okrzepnięciu balansu.
4. **Nowy kontekst domenowy** `Expedition`/`Profile` (kapitan, inwentarz,
   flota, postęp mapy) osobno od agregatu `Game`; profil w Postgresie
   (PvP odpadło jako blocker tej decyzji).
5. **Każdy plaster grywalny osobno** — nie budujemy katedry; kolejny etap
   zaczynamy od działającej gry.

## Plasterki (kolejność budowy)

- **Krok 0 — `WeaponSpec`**: refaktor parametrów broni w domenie bez zmiany
  zachowania (testy pilnują). Fundament pod technologie i broń-z-floty.
- **A — pętla bez ekonomii**: kilka wysp (na start choćby liniowo), każda =
  bitwa z zadaną flotą wroga + XP; ranga odblokowuje kolejne wyspy i statki.
  Dowodzi pętli mapa → bitwa → postęp.
- **B — flota jako majątek**: materiały, schematy, stocznie, budowa/remont,
  broń wynikająca ze składu floty.
- **C — prawdziwe wolne morze**: mgła świata, kartografia i handel mapami,
  eventy podróży, wyspy specjalne.

## Otwarte pytania

- Czy bitwy na wyspach są obowiązkowe (strażnicy), czy da się eksplorować
  unikając walki?
- Jak duże pierwsze morze (liczba wysp w plastrze A)?
- Szczegóły utraty statków: kiedy remont, a kiedy strata całkowita?
  (kierunek: remont po wygranej, strata przy przegranej — do zgrania w praktyce)
- Frakcje: moment wyboru (raz na kapitana? wielu kapitanów równolegle?).
- Tempo progresji: ile wieczorów ma trwać droga tratwa → lotniskowiec?

## Poczekalnia (pomysły wciąż aktualne, poza głównym torem)

- Perki kapitanów (Łowca/Artylerzysta/Torpedysta/Cichy…) — mogą wrócić jako
  wybory przy awansie na rangę.
- Eventy losowe **w bitwie** (mgła, cisza morska, prąd) — wymagają systemu
  eventów tury; na razie eventy żyją na mapie podróży.
- Miny na własnej planszy, replay partii (historia strzałów już jest
  w snapshotach — czysty frontend), statystyki/osiągnięcia, daily challenge
  (flota/świat z seeda dnia).
