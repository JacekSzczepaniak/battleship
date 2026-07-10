# CLAUDE.md — battleship (statki)

Projekt hobbystyczny: gra w statki, poligon do nauki DDD. **Nie jest powiązany
z finansowoexpress** — reguły tamtego projektu (CONTRIBUTING, dziennik statusu,
konwencja FEX-N) tu NIE obowiązują.

## Stack

- Backend: Symfony 7.3 / PHP >= 8.3, DDD (`src/Domain` / `Application` / `Infrastructure`)
- Frontend: Vue 3 + TypeScript (Vite), katalog `frontend/`
- DB: Postgres 16 (Docker), stan gry jako snapshot JSON w tabeli `games`
- Testy: Pest (Unit / Integration / Functional), uruchamiane w Dockerze przez `make`

## Workflow (lekki)

- Przed nietrywialną zmianą: krótki plan (kilka punktów) i lista plików.
- Przed commitem: testy adekwatne do zmiany (`make test-all`, minimum `make test-unit`).
- Bez dziennika statusu — historią projektu jest git log.
- Branch: `feature/...`, `fix/...`, `refactor/...` + opisowa nazwa (bez numerów ticketów).

## Commity

- **Agent commituje samodzielnie** po zakończeniu zmiany i zielonych testach.
  Push wykonuje Jacek.
- Format: temat po angielsku (imperative), body po polsku (opcjonalne),
  trailer: `Co-Authored-By: Claude <noreply@anthropic.com>`.
- Jeden logiczny commit = jedna zmiana; nie mieszać refaktoru z nowymi feature'ami.

## Uruchamianie i pułapki

- Kontenery: `make up` (app + db + redis), testy: `make test-all` / `test-unit` /
  `test-int` / `test-func` (targety funkcjonalne same przygotowują bazę testową).
- **Pest bez `-vv` w trybie nie-interaktywnym kończy się kodem 0 bez outputu i bez
  uruchomienia testów** — zawsze dodawaj `-vv` (targety make już to robią).
- Port 8080 (nginx) bywa zajęty przez inne projekty — do testów nginx nie jest potrzebny.
- Styl: PHP-CS-Fixer `@Symfony` (`make cs-fix`); PHPStan bez pliku konfiguracyjnego —
  uruchamiaj z jawną ścieżką i poziomem (np. `phpstan analyze src --level=5`).

## Stan techniczny / kierunek

- AI przeciwnika: jedna implementacja `Domain/Game/AI/HuntTargetAI` (hunt/target +
  snapshot stanu w polu `aiState` gry). Nie dodawać drugiej ścieżki AI.
- `BoardReadModel` ma `width()`/`height()` (plansze prostokątne); `TargetBoard`
  wciąż zakłada kwadrat (używany tylko przez `TurnLoop`/demo).
- Znane zadania: fix geometrii `Game::sendAirRaid()` (x/y pomylone z width/height,
  clamp pomija wiersz/kolumnę 0), `FleetGenerator` zamiast odczytu `$_ENV` w
  `Application/Game/PlaceFleet`, UI dla trybu fun (torpeda/sonar/nalot są tylko w API).
