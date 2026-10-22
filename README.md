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

- **Bitwa** — agregat `Domain/Game/Game` z dwiema stronami planszy. Bronie wynikają ze składu
  floty: kuter (2 pola) daje sonar, niszczyciel (3) torpedę, lotniskowiec (4) nalot; zatopiony
  nośnik odbiera broń. Transportowiec (5) broni nie daje — w bitwie to zwykły cel. Parametry broni opisuje `Domain/Game/Weapon/*Spec`.
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
  → Application\Game\FireShot          tura gracza → oznacz turę przeciwnika
      → Game::fireShot()               wynik: miss | hit | sunk | duplicate
      → OpponentTurn::respond()        ruch AI: HuntTargetAI (stan w aiState gry) + WeaponUseDecider
  → GameRepository::save()             snapshot JSON gry
  ← { result, finished, win, loss, turn, opponentMoves, opponentTorpedoLaunch }
```

Każda salwa, torpeda i nalot oddaje ruch AI; sonar turę zostawia. Po zakończonej bitwie o wyspę
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
| `POST` | `/games` | nowa gra: `mode` (`classic`/`fun`), `width`, `height`, opcjonalnie `ships` |
| `GET` | `/games/{id}` | projekcja gry: plansze, mgła wroga, bronie, tura |
| `POST` | `/games/{id}/fleet` | rozstawienie floty gracza |
| `POST` | `/games/{id}/shots` | salwa w pole `x`, `y` |
| `GET` | `/games/{id}/shots` | strzały gracza z wynikami |
| `POST` | `/games/{id}/torpedo` | torpeda z własnego niszczyciela (`x`, `y`, `direction`) |
| `POST` | `/games/{id}/sonar` | skan sonarem (krzyż), nie kończy tury |
| `POST` | `/games/{id}/air-raid` | nalot na obszar |
| `POST` | `/profiles` | nowy profil kapitana |
| `GET` | `/profiles/{id}/expedition` | profil, flota, typy statków i budynków, wyspy (bandera, budynki), świat, kontratak |
| `POST` | `/profiles/{id}/sail` | rejs o jeden sektor |
| `POST` | `/profiles/{id}/islands/{islandId}/battle` | bitwa o wyspę albo obrona wyspy pod banderą (kapitan musi stać na wyspie) |
| `POST` | `/profiles/{id}/islands/{islandId}/buildings` | budynek osady (`type`) na posiadanej wyspie |
| `POST` | `/profiles/{id}/battles/{gameId}/settle` | rozliczenie zakończonej bitwy |
| `POST` | `/profiles/{id}/ships` | budowa statku w stoczni |
| `POST` | `/profiles/{id}/ships/{shipId}/repair` | remont statku |

Poza `/api`: `GET /healthz`.
