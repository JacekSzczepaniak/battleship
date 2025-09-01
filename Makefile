# Użycie: make up | down | sh | composer | install | symfony-new | cc | stan | test | cs-fix

UID := $(shell id -u)
GID := $(shell id -g)

export UID
export GID

up: ## Uruchom kontenery (build + detach)
	docker compose up -d --build

down: ## Zatrzymaj i usuń kontenery
	docker compose down

logs: ## Podgląd logów (ostatnie 150, follow)
	docker compose logs -f --tail=150

sh: ## Wejdź do kontenera aplikacji (bash)
	docker compose exec app bash

composer: ## Uruchom composer w kontenerze, np. make composer cmd="outdated"
	docker compose exec app composer $(cmd)

install: ## Zainstaluj zależności composer (bez interakcji)
	docker compose exec app composer install --no-interaction

cc: ## Symfony cache clear (jeśli projekt już istnieje)
	docker compose exec app php bin/console cache:clear

pkg-api: ## Doinstaluj pakiety API (serializer, validator, http-client, messenger, routing, uid, annotations)
	docker compose exec app composer require symfony/serializer symfony/validator symfony/http-client symfony/messenger symfony/routing symfony/uid doctrine/annotations --no-interaction

pkg-doctrine: ## Doinstaluj pakiety Doctrine (orm-pack, doctrine-bundle)
	docker compose exec app composer require symfony/orm-pack doctrine/doctrine-bundle --no-interaction

pkg-dev: ## Doinstaluj pakiety deweloperskie (phpunit, pest, phpstan, cs-fixer, maker)
	docker compose exec app composer require --dev phpunit/phpunit pestphp/pest phpstan/phpstan friendsofphp/php-cs-fixer symfony/maker-bundle --no-interaction

migrate: ## Wykonaj migracje w env=dev
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

stan: ## Uruchom PHPStan (toleruj błędy wyjścia)
	docker compose exec app ./vendor/bin/phpstan analyze || true

cs-fix: ## Uruchom PHP-CS-Fixer (bez cache, toleruj błędy)
	docker compose exec app ./vendor/bin/php-cs-fixer fix --using-cache=no || true

.PHONY: test-all test-unit migrate-test test-int test-func help

# dodatkowe argumenty do Pest, np.: make test-int ARGS="-vvv --stop-on-failure"
ARGS ?= -vv --colors=always

# Pełny zestaw przez Pest (dev env)
test-all: ## Uruchom wszystkie testy (Pest)
	docker compose exec -T app ./vendor/bin/pest $(ARGS)

# Tylko unit (domena/aplikacja, dev env)
test-unit: ## Uruchom tylko testy jednostkowe (grupa unit)
	docker compose exec -T app ./vendor/bin/pest --group unit $(ARGS)

# Migracje w test env
migrate-test: ## Przygotuj bazę testową (utwórz i wykonaj migracje)
	docker compose exec -T app php -d variables_order=EGPCS bin/console doctrine:database:create --if-not-exists --env=test
	docker compose exec -T app php -d variables_order=EGPCS bin/console doctrine:migrations:migrate -n --env=test

# Integracje (Doctrine, DB testowa)
test-int: migrate-test ## Uruchom testy integracyjne (DB)
	docker compose exec -T -e APP_ENV=test -e KERNEL_CLASS=App\\Kernel app ./vendor/bin/pest tests/Integration $(ARGS)

# Funkcjonalne (API, WebTestCase)
test-func: migrate-test ## Uruchom testy funkcjonalne (HTTP, WebTestCase)
	docker compose exec -T -e APP_ENV=test -e KERNEL_CLASS=App\\Kernel app ./vendor/bin/pest tests/Functional $(ARGS)

help: ## Pokaż pomoc do dostępnych celów
	@printf "\nDostępne cele:\n\n"
	@awk 'BEGIN {FS = ":.*?## "}; /^[a-zA-Z0-9_.-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\nPrzykłady:\n"
	@printf "  make test-all ARGS=\"-vvv --stop-on-failure\"\n\n"
