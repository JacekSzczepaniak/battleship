# Statki

Gra w statki rozrośnięta w podróż: od rozbitka na tratwie do admirała z lotniskowcem.
Bitwy na morzu, wyspy ze stoczniami, flota, którą trzeba utrzymać przy życiu, i rangi
kapitana zdobywane za XP. Projekt hobbystyczny — poligon do nauki DDD.

![Port macierzysty](docs/images/port.png)

- wizja gry: [`docs/GAME_DESIGN.md`](docs/GAME_DESIGN.md)
- projekt UI (port, rozstawianie, bitwa, wynik): [`docs/design/ui-flow/`](docs/design/ui-flow/README.md)
- zadania: [tablica GitHub Projects](https://github.com/users/JacekSzczepaniak/projects/1)

## Stack

- backend: Symfony 7.3, PHP 8.3 (`php:8.3-fpm-alpine`), Doctrine, Postgres 16, Redis
- frontend: Vue 3 + TypeScript, Vite, `lucide-vue-next` (katalog `frontend/`)
- testy: Pest 4 (Unit / Integration / Functional), PHPStan, PHP-CS-Fixer (`@Symfony`)
- wszystko po stronie PHP działa w Dockerze; frontend uruchamiasz lokalnym Node

## Uruchomienie

Wymagania: Docker z Compose v2, Node 20.19+ (Vite 7), wpis w `/etc/hosts`:

```
127.0.0.1 statki.local
```

Pierwsze uruchomienie:

```bash
make up            # app (php-fpm) + web (nginx :8080) + database + redis
make install       # composer install w kontenerze
make migrate       # migracje bazy dev
make fe-install    # npm ci w frontend/
make fe-dev        # Vite pod http://statki.local:5173
```

| co | adres |
|---|---|
| gra (Vite dev) | http://statki.local:5173 |
| API (nginx) | http://statki.local:8080/api |
| healthcheck | http://statki.local:8080/healthz |
| Postgres z hosta | `127.0.0.1:54329`, baza/użytkownik/hasło `statki` |

Frontend bierze adres API z `VITE_API_URL` (`frontend/.env.development` dla `make fe-dev`,
`frontend/.env.production` dla buildu). Własne nadpisania trzymaj w `frontend/.env.*.local` —
są w `.gitignore`.

`make help` wypisuje wszystkie cele.

### Pułapki środowiska

- **Port 8080 bywa zajęty** (np. przez inny projekt z WordPressem). Testy nginx nie potrzebują —
  wystarczą `app`, `database` i `redis`. Jeśli potrzebujesz API z przeglądarki, przenieś nginx
  na inny port dodatkowym plikiem compose (Compose ≥ 2.24 rozumie `!override`):

  ```yaml
  # np. poza repo: ~/statki-ports.yaml
  services:
    web:
      ports: !override
        - "127.0.0.1:8081:80"
  ```

  i uruchom `docker compose -f docker-compose.yml -f compose.override.yaml -f ~/statki-ports.yaml up -d`.
  Wtedy `VITE_API_URL=http://statki.local:8081/api` w `frontend/.env.development.local`.
- **`statki.local` na macOS potrafi kosztować kilka sekund na żądanie.** Nazwy `.local` macOS
  rozwiązuje przez mDNS; `curl` do `statki.local` czekał ~5 s, ten sam adres przez `127.0.0.1`
  odpowiadał od razu. Jeśli UI działa ospale, wskaż API przez `127.0.0.1`.
- Po zmianach w `config/services.yaml` wyczyść cache testów:
  `docker compose exec app php bin/console cache:clear --env=test`.

## Testy i jakość

Pest zawsze startuje z `APP_ENV=test` (`phpunit.xml`), więc cały zestaw potrzebuje bazy
testowej. Śledzony `.env.test` wskazuje na `127.0.0.1`, co z wnętrza kontenera nie działa —
przy pierwszym uruchomieniu utwórz nieśledzony `.env.test.local`:

```
APP_ENV=test
APP_DEBUG=0
DATABASE_URL="postgresql://statki:statki@database:5432/statki_test?serverVersion=16&charset=utf8"
```

i przygotuj bazę: `make migrate-test` (cele `test-int` i `test-func` robią to same).

```bash
make test-all      # cały zestaw Pest (unit + integration + functional)
make test-unit     # tylko grupa unit
make test-int      # integracyjne (przygotowują bazę testową)
make test-func     # funkcjonalne API przez WebTestCase (przygotowują bazę testową)
make stan          # PHPStan (phpstan.neon)
make cs-fix        # PHP-CS-Fixer — poprawia pliki
make fe-build      # vue-tsc + vite build — jedyna kontrola typów frontendu
```

- **Pest bez `-vv` w trybie nieinteraktywnym kończy się kodem 0 bez outputu i bez uruchomienia
  testów.** Cele `make` dodają `-vv` same (`ARGS ?= -vv --colors=always`); wywołując Pest ręcznie,
  dodaj `-vv`. Dodatkowe flagi: `make test-all ARGS="-vvv --stop-on-failure"`.
- **CI** (`.github/workflows/symfony-ci.yml`) sprawdza tylko backend: PHPStan, PHP-CS-Fixer
  (dry-run) i Pest na Postgresie. Frontendu nie buduje — przed PR-em z frontendem `make fe-build`.

## Architektura

Cztery warstwy w `src/`:

| warstwa | co zawiera |
|---|---|
| `Domain` | reguły gry bez zależności od frameworka: `Game` (bitwa, plansze, bronie, AI), `Expedition` (profil kapitana, rangi, flota, wyspy, mapa świata), `Shared` (identyfikatory) |
| `Application` | przypadki użycia: `Game/*` (tworzenie gry, strzał, torpeda, sonar, nalot, tura AI), `Expedition/*` (profil, rejs, bitwa o wyspę, rozliczenie, stocznia) |
| `Infrastructure` | HTTP (kontrolery, mapowanie błędów), persystencja Doctrine (repozytoria + mappery snapshotów), repozytorium in-memory |

Interfejs to osobna aplikacja Vue w `frontend/`, rozmawiająca z backendem wyłącznie przez API.

Dwa konteksty, połączone tylko identyfikatorem gry:

- **Bitwa** — agregat `Domain/Game/Game` z dwiema stronami planszy. Bronie wynikają z wyposażenia
  statków (`Domain/Game/Weapon/Loadout`): domyślnie kuter (2 pola) daje sonar, niszczyciel (3)
  torpedę, lotniskowiec (4) nalot; zatopienie ostatniego nośnika danej broni ją odbiera.
  Transportowiec (5) broni nie daje — w bitwie to zwykły cel. Warianty statków (inne wyposażenie
  tej samej długości) podaje skład floty w rulesecie, a wróg dostaje je lustrzanie. Parametry
  broni opisuje `Domain/Game/Weapon/*Spec`. Technologie gracza (promień sonaru) trafiają do
  rulesetu przy starcie bitwy wyprawy i tylko po jego stronie — wróg ich nie dostaje.
- **Wyprawa** — agregat `Domain/Expedition/CaptainProfile`: XP i ranga (`Rank`, 9 stopni),
  flota jako majątek (statki giną albo czekają na remont), materiały, mapa świata z mgłą
  generowana z seeda profilu, rejestr bitew o wyspy.

**Persystencja:** stan agregatu to snapshot JSON w kolumnie `state` tabel `games` i `profiles`
(Postgres) — przez mappery w `Infrastructure/Persistence/Doctrine/Mapper`. Ranga nie jest
zapisywana; wynika z XP przy odczycie.

**Determinizm:** mapa świata i sztormy liczą się z seeda profilu, bez globalnego RNG. Losowość
bitwy siedzi za portami `Domain\Game\FleetGenerator` (flota AI) i `Application\Game\WeaponUseDecider`
(czy AI użyje broni): w dev są losowe, w środowisku `test` deterministyczne (`config/services.yaml`,
`when@test`), więc testy znają pozycje floty przeciwnika.

**Przebieg strzału** (`POST /api/games/{id}/shots`):

```
FireShotController
  → Application\Game\FireShot          tura gracza → koszt energii (fun) → oznacz turę przeciwnika
      → Game::fireShot()               wynik: miss | hit | sunk | duplicate
      → OpponentTurn::respond()        ruch AI: HuntTargetAI (stan w aiState gry) + WeaponUseDecider
  → GameRepository::save()             snapshot JSON gry
  ← { result, finished, win, loss, turn, opponentMoves, opponentTorpedoLaunch }
```

Każda salwa, torpeda i nalot oddaje ruch AI; sonar turę zostawia. W trybie `fun` działania kosztują
energię (#59): 3⚡ na turę, salwa 1, sonar 1, torpeda 2, nalot 3, ruch statku 1; pula wraca po ruchu
AI, a `POST /games/{id}/end-turn` oddaje turę bez ataku. Ruch statku (#60) jest raz na turę i nie kończy
tury. Bez energii akcja kończy się `409 NOT_ENOUGH_ENERGY`. W bitwach wyprawy z bronią specjalną
przy ostatnim statku wroga można raz na bitwę spróbować abordażu (#118): ⚡3, rzut k6 z seeda gry
i numeru tury, sukces od 5+ (4+ przy połowie pól statku trafionych). Sukces wygrywa bitwę, a zdobyty
statek czeka w profilu na decyzję: dołącz (uszkodzony, bez wymogu rangi) albo rozbierz (połowa kosztu
budowy do ładowni).
Tryb `classic` jest bez energii (`energy: null`). Po zakończonej bitwie o wyspę
`POST /api/profiles/{id}/battles/{gameId}/settle` rozlicza XP, materiały i straty floty ze stanu
gry — idempotentnie (drugie wywołanie zwraca zera). Pierwsza wygrana o wyspę stawia na niej banderę
kapitana; złoże pracuje tylko na wyspie pod banderą. Wyspa pod banderą nie przyjmuje kolejnych bitew
o zdobycie — walczy się tam tylko w obronie przed zapowiedzianym kontratakiem. Napastnik (AI) oddaje
wtedy pierwszą salwę zaraz po rozstawieniu floty gracza. Przy pełnej banderze (wszystkie wyspy
akwenu) pierwszy strzał należy do gracza, a napastnik płynie bez największego statku — skład floty
AI może się wtedy różnić od składu gracza (`opponentShips` w `GET /api/games/{id}`). Na posiadanej
wyspie stawia się budynki osady (tartak i kopalnia podwajają urobek złoża, rozbudowa stoczni
podnosi jej poziom o 1, fort poza Zatoką Rozbitka zamienia utratę wyspy pod nieobecność floty
w grabież połowy magazynu); bez bandery budynki stoją nieczynne.

## API

Wszystkie ścieżki pod `/api`, JSON. Pełna lista: `docker compose exec app php bin/console debug:router`.

| metoda | ścieżka | co robi |
|---|---|---|
| `POST` | `/games` | nowa gra: `mode` (`classic`/`fun`), `width`, `height`, opcjonalnie `ships` i `variants` (`{"3": [{"sonar": 2}]}`); `siege: true` (+ opcjonalny `seed`) = oblężenie fortu 10×10 z zasadami fun, odpowiedź z `fortSeed` |
| `GET` | `/games/{id}` | projekcja gry: plansze, mgła wroga, bronie (`used`/`limit`, sonar też `radius`), warianty obu stron (`variants`/`opponentVariants`), tura, energia gracza (`energy`: `left`/`max`/`costs`, `null` w classic), ruch statku w tej turze (`moveUsed`), abordaż (`boarding`: `available`, `blocker`, `threshold`, `modifiers`, `cost`, `attempt`; `null` poza bitwą wyprawy z energią i w oblężeniu), fort w oblężeniu (`fort`: `guns` `left`/`total`, `revealed` z rodzajem trafionych konstrukcji, pola `explosion`; `null` w zwykłej bitwie) |
| `POST` | `/games/{id}/fleet` | rozstawienie floty gracza; statek z wariantem niesie `w` (`{"sonar": 2}`) |
| `POST` | `/games/{id}/shots` | salwa w pole `x`, `y` |
| `GET` | `/games/{id}/shots` | strzały gracza z wynikami |
| `POST` | `/games/{id}/torpedo` | torpeda z własnego niszczyciela (`x`, `y`, `direction`) |
| `POST` | `/games/{id}/sonar` | skan sonarem (krzyż o promieniu 3, gracz 4 po Sonarze dalekiego zasięgu), 1⚡, nie kończy tury |
| `POST` | `/games/{id}/air-raid` | nalot na obszar |
| `POST` | `/games/{id}/move` | ruch statku o 1 pole (`x`, `y` = dowolne pole statku, `direction` `N`/`E`/`S`/`W`), 1⚡, raz na turę, nie kończy tury; tylko `fun` (w classic `422 SHIP_MOVE_NOT_AVAILABLE`); odrzucenia: `422 SHIP_MOVE_*`, drugi ruch `409 SHIP_ALREADY_MOVED` |
| `POST` | `/games/{id}/board` | abordaż ostatniego statku wroga (#118): rzut k6, ⚡3, kończy turę, raz na bitwę; `422 BOARDING_NOT_AVAILABLE` poza bitwą wyprawy z energią, `422 BOARDING_NOT_LAST_SHIP`, `409 BOARDING_ALREADY_ATTEMPTED` |
| `POST` | `/games/{id}/end-turn` | „Zakończ turę” bez ataku (tylko `fun`; w classic `422 END_TURN_NOT_AVAILABLE`) |
| `POST` | `/profiles` | nowy profil kapitana |
| `GET` | `/profiles/{id}/expedition` | profil, flota, typy statków i budynków, wyspy bieżącego akwenu (bandera, budynki), świat (akwen i jego motyw, bramy z powodem blokady i strażnikiem, port handlowy `tradePort` z kursem rynku i magazynem), statki zdobyte abordażem (`prizes`, ze zwrotem z rozbiórki i `studyTechnology` do zbadania konstrukcji), kontratak, drzewko badań, dziennik kapitana (50 ostatnich zdarzeń, od najnowszego), zapisane zestawy floty (`fleetPresets`), spotkanie czekające na decyzję ze skutkami wyborów (`encounter`) i skład nierozliczonych bitew (`battleSquads`: gameId → id statków) |
| `POST` | `/profiles/{id}/sail` | rejs za 1 tik: o jeden sektor, z Żeglugą dwusektorową także o dwa |
| `POST` | `/profiles/{id}/islands/{islandId}/battle` | bitwa o wyspę albo obrona wyspy pod banderą (kapitan musi stać na wyspie) |
| `POST` | `/profiles/{id}/islands/{islandId}/buildings` | budynek osady (`type`) na posiadanej wyspie |
| `POST` | `/profiles/{id}/islands/{islandId}/research` | start badania (`technology`) w uczelni wyspy, na której stoi kapitan; technologia ze zdobyczy daje `409 TECHNOLOGY_BOARDING_ONLY` |
| `POST` | `/profiles/{id}/islands/{islandId}/siege` | oblężenie fortu oblężonej twierdzy (po wygranej potyczce, flota przy wyspie); wygrana stawia banderę |
| `POST` | `/profiles/{id}/gates/{gateId}/battle` | bitwa ze strażnikiem bramy (flota na sektorze bramy, ranga strażnika); wygrana otwiera bramę |
| `POST` | `/profiles/{id}/gates/{gateId}/pass` | przejście przez otwartą bramę na sektor bramy drugiego akwenu, za 1 tik |
| `POST` | `/profiles/{id}/encounter/decide` | decyzja przy spotkaniu na morzu: `decision` `pass` (minięcie handlarza), `flee` (ucieczka przed piratem: 1 tik i 20% ładunku) albo `ransom` (okup w złocie z ładowni); `409 NO_ENCOUNTER`, `409 ENCOUNTER_CHOICE_UNAVAILABLE`, `409 NOT_ENOUGH_GOLD` |
| `POST` | `/profiles/{id}/encounter/trade` | handel z handlarzem jednym zleceniem za 1 tik: `sell` i `buy` (`{wood, steel}`) za złoto z ładowni, ceny o 25% gorsze niż w porcie |
| `POST` | `/profiles/{id}/encounter/battle` | napad na handlarza albo walka z piratem (opcjonalnie `shipIds`); łup do ładowni. Dopóki spotkanie czeka, `/sail` zwraca `409 ENCOUNTER_PENDING` |
| `POST` | `/profiles/{id}/battles/{gameId}/settle` | rozliczenie zakończonej bitwy |
| `POST` | `/profiles/{id}/ships` | budowa statku w stoczni (`type`, `islandId`, opcjonalnie `variant` niszczyciela: `torpedowy`/`zwiadowczy`) |
| `POST` | `/profiles/{id}/ships/{shipId}/repair` | remont statku |
| `PUT` | `/profiles/{id}/fleet/field-repairs` | przełącznik napraw kutrów remontowych poza stocznią (`enabled: bool`, #152), bez tiku; w odpowiedzi `fieldRepairs`; stan w `profile.fieldRepairs` widoku wyprawy; `400 VALIDATION_ERROR` |
| `POST` | `/profiles/{id}/fleet/repair` | remont całej floty w stoczni portu (`islandId`): uszkodzone statki od najtańszego, płaci magazyn wyspy, resztę ładownia, bez tiku; pominięte statki wracają z powodem (`shipyard-too-low`, `not-enough-resources`); w odpowiedzi `repaired`, `skipped` (`ship`, `reason`), `paid`, `stockpile`, `cargo`; `409 NO_SHIPYARD`, `409 NOT_AT_ISLAND` |
| `POST` | `/profiles/{id}/ships/{shipId}/scrap` | złomowanie statku w stoczni portu (`islandId`, stocznia dowolnego poziomu): połowa kosztu budowy do magazynu wyspy, za uszkodzony mniej o koszt remontu, tratwa bez zwrotu; w odpowiedzi `refund`, `stockpile`, `cargo`; `409 NO_SHIPYARD`, `409 SHIP_IN_BATTLE` (statek w nierozliczonej bitwie), `409 LAST_SEAWORTHY_SHIP` (ostatnia sprawna jednostka) |
| `POST` | `/profiles/{id}/trade-ports/{portId}/trade` | rynek portu handlowego akwenu floty (flota w porcie): `side` `buy`/`sell`, `good` `wood`/`steel`, `gold` (porcje po 1 złoto, ≥ 1); kupno po kursie akwenu, sprzedaż po połowie ceny; płaci magazyn portu, resztę ładownia, a towar i złoto trafiają do ładowni; 1 tik; w odpowiedzi `paid`, `received`, `cargo`, `stockpile`, `tick`; `409 NOT_ENOUGH_RESOURCES`, `409 CARGO_FULL`, `409 NOT_AT_TRADE_PORT`, `404 TRADE_PORT_NOT_FOUND` (port innego akwenu) |
| `POST` | `/profiles/{id}/trade-ports/{portId}/ships/{shipId}/sell` | sprzedaż statku za złoto w porcie handlowym, bez stoczni: zwrot jak przy złomowaniu przeliczony po kursie sprzedaży akwenu, złoto do ładowni, 1 tik; blokady jak przy złomowaniu, `409 CARGO_FULL`, gdy ładownia bez statku nie pomieści ładunku ze złotem |
| `POST` | `/profiles/{id}/prizes/{prizeId}` | decyzja o statku zdobytym abordażem: `decision` `join` (do floty jako uszkodzony) albo `scrap` (połowa kosztu budowy do ładowni, nadmiar przepada) albo `study` (zbadaj konstrukcję: statek przepada, wariant ze zdobyczy trafia do stoczni, w odpowiedzi `technology`); `404 PRIZE_NOT_FOUND`, `409 PRIZE_NOT_STUDIABLE` (wariant znany albo statek bez wariantu ze zdobyczy) |
| `POST` | `/profiles/{id}/fleet-presets` | zapis zestawu floty z rozmieszczeniem: `name` (1–30 znaków), `board` `{w, h}`, `ships` `[{shipId, x, y, o: H\|V}]`; pozycje jak przy rozstawianiu (granice, statki się nie stykają); ta sama nazwa (bez względu na wielkość liter) nadpisuje zestaw (`200`), nowy dostaje `201`; `409 FLEET_PRESET_LIMIT` przy szóstym, `404 SHIP_NOT_FOUND` dla statku spoza floty |
| `PATCH` | `/profiles/{id}/fleet-presets/{presetId}` | zmiana nazwy zestawu (`name`); `409 FLEET_PRESET_NAME_TAKEN`, `404 FLEET_PRESET_NOT_FOUND` |
| `DELETE` | `/profiles/{id}/fleet-presets/{presetId}` | usunięcie zestawu (`204`) |

Poza `/api`: `GET /healthz`.
